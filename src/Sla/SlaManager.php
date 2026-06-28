<?php

declare(strict_types=1);

namespace Selli\Ticketing\Sla;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Enums\SlaTarget;
use Selli\Ticketing\Events\SlaBreached;
use Selli\Ticketing\Events\SlaThresholdReached;
use Selli\Ticketing\Models\SlaClock;
use Selli\Ticketing\Models\SlaPolicy;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Workflow\WorkflowManager;

/**
 * Owns the lifecycle of SLA timers: starting them when a ticket opens, stopping
 * the first-response clock on the first agent reply, pausing/resuming while a
 * ticket waits on the customer, completing the resolution clock on resolution,
 * and sweeping for thresholds and breaches.
 */
class SlaManager
{
    public function __construct(
        protected SlaPolicyResolver $policies,
        protected CalendarResolver $calendars,
        protected WorkflowManager $workflow,
        protected TenantContext $tenant,
    ) {}

    /**
     * Start the first-response and resolution clocks for a freshly opened ticket.
     */
    public function startClocks(Ticket $ticket): void
    {
        $policy = $this->policies->resolve($ticket);

        if ($policy === null) {
            return;
        }

        $calendar = $this->calendars->forPolicy($policy);
        $now = now();

        if ($policy->first_response_minutes !== null) {
            $this->writeClock($ticket, SlaTarget::FirstResponse, $now, $calendar->addMinutes($now, $policy->first_response_minutes), $policy->first_response_minutes, $policy->business_hours_id);

            // A ticket opened already carrying a first response (e.g. a split of
            // an answered thread) still gets a clock row, immediately completed,
            // so sweeps and reporting see it like any other.
            if ($ticket->first_response_at !== null) {
                $this->completeFirstResponseClock($ticket);
            }
        }

        if ($policy->resolution_minutes !== null) {
            $this->writeClock($ticket, SlaTarget::Resolution, $now, $calendar->addMinutes($now, $policy->resolution_minutes), $policy->resolution_minutes, $policy->business_hours_id);
        }
    }

    /**
     * React to a public message: an agent reply completes the first-response and
     * next-response clocks; a requester (customer) reply starts/restarts the
     * next-response clock.
     */
    public function handleMessage(Ticket $ticket, TicketMessage $message): void
    {
        if ($message->visibility !== MessageVisibility::Public) {
            return;
        }

        if ($this->isRequesterAuthor($ticket, $message)) {
            $this->startNextResponse($ticket);

            return;
        }

        // Only a real agent reply (author implements CanActOnTickets) completes
        // the response clocks — a public watcher/CC note or a system post with no
        // author must not stop the first/next-response timers.
        if ($this->isAgentAuthor($message)) {
            $this->handleFirstResponse($ticket);
            $this->completeClock($ticket, SlaTarget::NextResponse);
        }
    }

    protected function isAgentAuthor(TicketMessage $message): bool
    {
        if ($message->author_id === null) {
            return false;
        }

        return $message->author instanceof CanActOnTickets;
    }

    /**
     * Reconcile first_response_at after messages are reparented (split/merge)
     * from the ticket's currently-attached conversation (earliest qualifying
     * public agent reply).
     *
     * Additive contexts (merge target, fresh split child) use the default
     * non-destructive mode: set when unset or pull earlier, but never erase a
     * stamp on a transient negative nor push it later. The split SOURCE — whose
     * conversation actually shrank — passes $allowClear so it stops looking
     * answered (and its clock restarts) when every qualifying reply moved away.
     */
    public function reconcileFirstResponse(Ticket $ticket, bool $allowClear = false): void
    {
        $earliest = $this->earliestAgentReplyAt($ticket);

        // Never record a first response before the ticket itself existed: a
        // split/merge can inherit an older reply, and a first_response_at that
        // predates created_at would make "time to first response" go negative.
        $stamp = null;

        if ($earliest !== null) {
            $stamp = $ticket->created_at !== null && $earliest->lessThan($ticket->created_at)
                ? $ticket->created_at
                : $earliest;
        }

        if (! $allowClear) {
            // Additive: only adopt when unset or strictly earlier.
            if ($stamp === null) {
                if ($ticket->first_response_at !== null) {
                    $this->completeFirstResponseClock($ticket);
                }

                return;
            }

            $current = $ticket->first_response_at;

            if ($current === null || $stamp->lessThan($current)) {
                $this->persistFirstResponse($ticket, $stamp);
            }

            $this->completeFirstResponseClock($ticket);

            return;
        }

        // Authoritative clear is moot on a resolved/closed ticket (SLA stopped):
        // leave its historical stamp and completed clock untouched.
        if ($stamp === null && $this->isStoppedState($ticket)) {
            return;
        }

        if (! $this->sameInstant($stamp, $ticket->first_response_at)) {
            $this->persistFirstResponse($ticket, $stamp);
        }

        if ($stamp !== null) {
            $this->completeFirstResponseClock($ticket);
        } else {
            // Every qualifying reply was moved away — restart the first-response
            // timer so the now-unanswered ticket is measured again from open.
            $this->reopenFirstResponseClock($ticket);
        }
    }

    protected function persistFirstResponse(Ticket $ticket, ?Carbon $stamp): void
    {
        Ticketing::ticketModel()::query()->withoutTenancy()
            ->whereKey($ticket->getKey())
            ->update(['first_response_at' => $stamp]);

        $ticket->first_response_at = $stamp;
    }

    protected function sameInstant(?Carbon $a, ?Carbon $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->equalTo($b);
    }

    /**
     * Whether the ticket is in a resolved/closed (SLA-stopping) state.
     */
    protected function isStoppedState(Ticket $ticket): bool
    {
        $workflow = $this->workflowKey($ticket);

        return in_array(
            $ticket->status,
            $this->workflow->driver()->statesForSemantic($workflow, 'closed'),
            true,
        );
    }

    /**
     * Restart a completed first-response clock (no qualifying reply remains),
     * recomputing its deadline from the original start and clearing alert state.
     */
    protected function reopenFirstResponseClock(Ticket $ticket): void
    {
        $clock = $this->clock($ticket, SlaTarget::FirstResponse);

        if ($clock === null || ! $clock->isCompleted()) {
            return;
        }

        // Never resurrect a clock on a resolved/closed ticket: those states stop
        // all SLA timers, so reopening with a historic due_at would let the
        // sweeper breach a stopped ticket.
        if ($this->isStoppedState($ticket)) {
            return;
        }

        $policy = $this->policies->resolve($ticket);
        $minutes = $policy !== null ? $this->minutesFor($policy, SlaTarget::FirstResponse) : null;

        if ($policy === null || $minutes === null) {
            // No first-response coverage anymore — leave the completed clock as
            // is. Re-opening it with a historic due_at would let the sweeper
            // breach a target the policy no longer covers.
            return;
        }

        $calendar = $this->calendars->forPolicy($policy);

        $clock->forceFill([
            'completed_at' => null,
            'breached_at' => null,
            'threshold_notified' => false,
            'paused_at' => null,
            'remaining_minutes' => null,
            'budget_minutes' => $minutes,
            'business_hours_id' => $policy->business_hours_id,
            'due_at' => $calendar->addMinutes($clock->started_at, $minutes),
        ])->save();

        // If the ticket is currently waiting on the customer, the reopened clock
        // must be paused too — not left running through the wait window.
        if (in_array($ticket->status, $this->pauseStates($ticket), true)) {
            $this->pauseClocks($ticket);
        }
    }

    /**
     * Force the first-response clock (if one exists) to the ticket's
     * first_response_at, clamped to never precede the clock's started_at, even
     * if it was previously completed at a later time.
     */
    protected function completeFirstResponseClock(Ticket $ticket): void
    {
        if ($ticket->first_response_at === null) {
            return;
        }

        $clock = $this->clock($ticket, SlaTarget::FirstResponse);

        if ($clock === null) {
            return;
        }

        $completedAt = $ticket->first_response_at;

        if ($completedAt->lessThan($clock->started_at)) {
            $completedAt = $clock->started_at;
        }

        $updates = ['completed_at' => $completedAt, 'paused_at' => null];

        // A retroactively-applied earlier reply may now meet a deadline the clock
        // had already breached — clear the stale breach so reporting/state agree.
        if ($completedAt->lessThanOrEqualTo($clock->due_at)) {
            $updates['breached_at'] = null;
        }

        $clock->forceFill($updates)->save();
    }

    /**
     * The created_at of the earliest public reply by an agent who is not the
     * ticket's requester (mirrors PostMessage's "first response" rule), or null.
     */
    protected function earliestAgentReplyAt(Ticket $ticket): ?Carbon
    {
        /** @var list<string> $requesterKeys */
        $requesterKeys = $ticket->participants()
            ->withoutTenancy()
            ->where('role', ParticipantRole::Requester->value)
            ->get(['participant_type', 'participant_id'])
            ->map(fn (TicketParticipant $p): string => $p->participant_type.':'.$p->participant_id)
            ->all();

        $messageModel = Ticketing::ticketMessageModel();

        /** @var Collection<int, TicketMessage> $messages */
        $messages = $messageModel::query()->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->where('visibility', MessageVisibility::Public->value)
            ->whereNotNull('author_id')
            ->orderBy('created_at')
            ->orderBy((new $messageModel)->getKeyName())
            ->get();

        foreach ($messages as $message) {
            if (in_array($message->author_type.':'.$message->author_id, $requesterKeys, true)) {
                continue;
            }

            if (! $this->isAgentAuthor($message)) {
                continue;
            }

            return $message->created_at;
        }

        return null;
    }

    /**
     * Complete the first-response clock once an agent has replied publicly.
     */
    public function handleFirstResponse(Ticket $ticket): void
    {
        if ($ticket->first_response_at === null) {
            return;
        }

        $clock = $this->clock($ticket, SlaTarget::FirstResponse);

        if ($clock !== null && ! $clock->isCompleted()) {
            $clock->forceFill(['completed_at' => $ticket->first_response_at])->save();
        }
    }

    /**
     * Start (or restart) the next-response clock after a customer reply.
     */
    protected function startNextResponse(Ticket $ticket): void
    {
        $policy = $this->policies->resolve($ticket);

        if ($policy === null || $policy->next_response_minutes === null) {
            return;
        }

        $calendar = $this->calendars->forPolicy($policy);
        $now = now();

        $this->writeClock($ticket, SlaTarget::NextResponse, $now, $calendar->addMinutes($now, $policy->next_response_minutes), $policy->next_response_minutes, $policy->business_hours_id);
    }

    protected function completeClock(Ticket $ticket, SlaTarget $target): void
    {
        $clock = $this->clock($ticket, $target);

        if ($clock !== null && ! $clock->isCompleted()) {
            $clock->forceFill(['completed_at' => now(), 'paused_at' => null])->save();
        }
    }

    protected function isRequesterAuthor(Ticket $ticket, TicketMessage $message): bool
    {
        if ($message->author_id === null) {
            return false;
        }

        $model = Ticketing::ticketParticipantModel();

        return $model::query()
            ->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->where('role', ParticipantRole::Requester->value)
            ->where('participant_type', $message->author_type)
            ->where('participant_id', $message->author_id)
            ->exists();
    }

    /**
     * Pause or resume the clocks based on whether the new state is a pause state.
     */
    public function syncForTransition(Ticket $ticket, string $from, string $to): void
    {
        // Decide purely on the destination state: entering a pause state pauses
        // running clocks; entering any non-pause state resumes paused ones. This
        // is robust to the pause list changing after a clock was paused (resume
        // no longer depends on the previous state still being a pause state).
        if (in_array($to, $this->pauseStates($ticket), true)) {
            $this->pauseClocks($ticket);
        } else {
            $this->resumeClocks($ticket);
        }
    }

    /**
     * Complete the resolution clock on resolution.
     */
    public function handleResolved(Ticket $ticket): void
    {
        // Resolving stops every running/paused SLA timer for the ticket — not
        // just resolution — so a resolved ticket can never breach or trip a
        // threshold (and a resume on the way out of a pause state can't leave a
        // first/next-response clock running).
        $model = Ticketing::slaClockModel();

        $model::query()
            ->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->whereNull('completed_at')
            ->update(['completed_at' => now(), 'paused_at' => null]);
    }

    /**
     * Restart the resolution clock when a ticket is reopened.
     */
    public function handleReopened(Ticket $ticket): void
    {
        $policy = $this->policies->resolve($ticket);

        if ($policy === null || $policy->resolution_minutes === null) {
            return;
        }

        $calendar = $this->calendars->forPolicy($policy);
        $now = now();

        $this->writeClock($ticket, SlaTarget::Resolution, $now, $calendar->addMinutes($now, $policy->resolution_minutes), $policy->resolution_minutes, $policy->business_hours_id);
    }

    /**
     * Sweep running clocks for thresholds and breaches. Each clock is processed
     * within its own tenant so policies/calendars resolve correctly.
     */
    public function sweep(int $thresholdPercent = 75, int $chunk = 200): void
    {
        $model = Ticketing::slaClockModel();
        $now = now();

        $model::query()
            ->withoutTenancy()
            ->whereNull('completed_at')
            ->whereNull('paused_at')
            ->where(function ($query) use ($now): void {
                $query->where('due_at', '<=', $now)
                    ->orWhere('threshold_notified', false);
            })
            ->chunkById($chunk, function ($clocks) use ($thresholdPercent, $now): void {
                foreach ($clocks as $clock) {
                    $this->tenant->forTenant(
                        $clock->getAttribute($this->tenant->column()),
                        fn () => $this->evaluate($clock, $thresholdPercent, $now),
                    );
                }
            });
    }

    /**
     * Recompute the due_at of running clocks from their start using the current
     * policy (e.g. after a policy change).
     */
    public function recalculate(Ticket $ticket): void
    {
        $policy = $this->policies->resolve($ticket);
        $model = Ticketing::slaClockModel();

        if ($policy === null) {
            // No SLA coverage anymore — stop every incomplete clock so escalation
            // no longer fires for a ticket without a policy.
            $model::query()
                ->withoutTenancy()
                ->where('ticket_id', $ticket->getKey())
                ->whereNull('completed_at')
                ->update(['completed_at' => now(), 'paused_at' => null]);

            return;
        }

        $calendar = $this->calendars->forPolicy($policy);

        // Consider all incomplete clocks (running and paused): a removed target
        // must stop even a paused clock, otherwise resume would reactivate it
        // with a stale budget/calendar.
        $clocks = $model::query()
            ->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->whereNull('completed_at')
            ->get();

        foreach ($clocks as $clock) {
            $minutes = $this->minutesFor($policy, $clock->target);

            if ($minutes === null) {
                // The target was removed from the policy — stop the timer (even
                // if paused) so it no longer sweeps or resumes.
                $clock->forceFill(['completed_at' => now(), 'paused_at' => null])->save();

                continue;
            }

            // Paused clocks stay frozen: they are recomputed from their captured
            // budget on resume, so overwriting due_at here would desync them.
            if ($clock->isPaused()) {
                continue;
            }

            // Recompute the deadline/budget/calendar and clear stale alert state
            // so the next sweep re-evaluates against the new deadline.
            $clock->forceFill([
                'budget_minutes' => $minutes,
                'business_hours_id' => $policy->business_hours_id,
                'due_at' => $calendar->addMinutes($clock->started_at, $minutes),
                'breached_at' => null,
                'threshold_notified' => false,
            ])->save();
        }

        // Start clocks for targets newly enabled on the policy that have no row
        // yet. (Next-response is event-driven on customer replies, not here.)
        foreach ([SlaTarget::FirstResponse, SlaTarget::Resolution] as $target) {
            $minutes = $this->minutesFor($policy, $target);

            if ($minutes === null) {
                continue;
            }

            // Skip only when an *incomplete* clock already exists. A previously
            // completed clock (e.g. target was removed then re-enabled) is
            // restarted via writeClock's updateOrCreate.
            $existing = $this->clock($ticket, $target);

            if ($existing !== null && ! $existing->isCompleted()) {
                continue;
            }

            $now = now();
            $this->writeClock($ticket, $target, $now, $calendar->addMinutes($now, $minutes), $minutes, $policy->business_hours_id);

            // A first-response target enabled AFTER the ticket was already
            // answered still gets a completed row (clamped), so reporting/sweeps
            // aren't left without a first-response clock.
            if ($target === SlaTarget::FirstResponse && $ticket->first_response_at !== null) {
                $this->completeFirstResponseClock($ticket);
            }
        }
    }

    protected function evaluate(SlaClock $clock, int $thresholdPercent, Carbon $now): void
    {
        $ticket = $this->loadTicket($clock);

        if ($ticket === null) {
            // The ticket is gone (e.g. soft-deleted) — stop the orphaned clock so
            // it no longer sweeps forever.
            $this->claim($clock, ['completed_at' => $now, 'paused_at' => null], 'completed_at');

            return;
        }

        if ($clock->breached_at === null && $clock->due_at->lessThanOrEqualTo($now)) {
            // Atomically claim the breach so two concurrent sweeps emit it once,
            // and only while the clock is still active (not resolved/paused).
            if ($this->claim($clock, ['breached_at' => $now], 'breached_at')) {
                $clock->breached_at = $now;
                SlaBreached::dispatch($ticket, $clock);
            }

            return;
        }

        // Don't emit a threshold warning once the clock has already breached.
        if (! $clock->threshold_notified && $clock->breached_at === null
            && $this->fractionConsumed($clock, $now) >= $thresholdPercent / 100) {
            if ($this->claim($clock, ['threshold_notified' => true], 'threshold_notified', false)) {
                $clock->threshold_notified = true;
                SlaThresholdReached::dispatch($ticket, $clock, $thresholdPercent);
            }
        }
    }

    /**
     * Atomically set $values on a clock only if (a) it is still active
     * (not completed, not paused) and (b) $guardColumn still holds the expected
     * sentinel. Returns true when this caller won the claim — guarding against a
     * concurrent resolve/pause between the sweep query and this update.
     *
     * @param  array<string, mixed>  $values
     */
    protected function claim(SlaClock $clock, array $values, string $guardColumn, mixed $expected = null): bool
    {
        $model = Ticketing::slaClockModel();

        $query = $model::query()
            ->withoutTenancy()
            ->whereKey($clock->getKey())
            ->whereNull('completed_at')
            ->whereNull('paused_at');

        $query = $expected === null
            ? $query->whereNull($guardColumn)
            : $query->where($guardColumn, $expected);

        return $query->update($values) >= 1;
    }

    protected function fractionConsumed(SlaClock $clock, Carbon $now): float
    {
        $budget = $clock->budget_minutes;

        if ($budget === null || $budget <= 0) {
            return 0.0;
        }

        // Consumed = budget − working-minutes-left-to-deadline, using the clock's
        // own calendar. Because due_at is pushed out on resume, the remaining
        // figure already excludes paused time, so the fraction reflects only the
        // real share of budget used.
        $remaining = $this->calendarFor($clock)->minutesBetween($now, $clock->due_at);
        $consumed = max(0, $budget - $remaining);

        return min(1.0, $consumed / $budget);
    }

    protected function pauseClocks(Ticket $ticket): void
    {
        $now = now();

        foreach ($this->runningClocks($ticket) as $clock) {
            $remaining = $this->calendarFor($clock)->minutesBetween($now, $clock->due_at);
            $clock->forceFill(['paused_at' => $now, 'remaining_minutes' => $remaining])->save();
        }
    }

    protected function resumeClocks(Ticket $ticket): void
    {
        $now = now();
        $model = Ticketing::slaClockModel();

        $clocks = $model::query()
            ->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->whereNotNull('paused_at')
            ->whereNull('completed_at')
            ->get();

        foreach ($clocks as $clock) {
            $clock->forceFill([
                'due_at' => $this->calendarFor($clock)->addMinutes($now, (int) $clock->remaining_minutes),
                'paused_at' => null,
                'remaining_minutes' => null,
            ])->save();
        }
    }

    /**
     * The working calendar a clock was built against (kept on the clock itself).
     */
    protected function calendarFor(SlaClock $clock): BusinessHours
    {
        return $this->calendars->forBusinessHoursId($clock->business_hours_id);
    }

    /**
     * @return Collection<int, SlaClock>
     */
    protected function runningClocks(Ticket $ticket): object
    {
        $model = Ticketing::slaClockModel();

        return $model::query()
            ->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->running()
            ->get();
    }

    protected function clock(Ticket $ticket, SlaTarget $target): ?SlaClock
    {
        $model = Ticketing::slaClockModel();

        return $model::query()
            ->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->where('target', $target->value)
            ->first();
    }

    protected function writeClock(Ticket $ticket, SlaTarget $target, CarbonInterface $startedAt, CarbonInterface $dueAt, int $budgetMinutes, int|string|null $businessHoursId): void
    {
        $model = Ticketing::slaClockModel();

        $model::query()->withoutTenancy()->updateOrCreate(
            array_merge($ticket->tenantAttributes(), [
                'ticket_id' => $ticket->getKey(),
                'target' => $target->value,
            ]),
            [
                'budget_minutes' => $budgetMinutes,
                'business_hours_id' => $businessHoursId,
                'started_at' => $startedAt,
                'due_at' => $dueAt,
                'paused_at' => null,
                'remaining_minutes' => null,
                'breached_at' => null,
                'completed_at' => null,
                'threshold_notified' => false,
            ],
        );
    }

    /**
     * @return list<string>
     */
    protected function pauseStates(Ticket $ticket): array
    {
        $policy = $this->policies->resolve($ticket);

        if (is_array($policy?->pause_in_states)) {
            return array_values($policy->pause_in_states);
        }

        $workflow = $this->workflowKey($ticket);

        return $this->workflow->driver()->statesForSemantic($workflow, 'paused');
    }

    protected function workflowKey(Ticket $ticket): string
    {
        $typeModel = Ticketing::ticketTypeModel();
        $type = $typeModel::query()->withoutTenancy()->whereKey($ticket->ticket_type_id)->first();
        $workflow = $type?->workflow;

        return is_string($workflow) && $workflow !== '' ? $workflow : 'default';
    }

    protected function loadTicket(SlaClock $clock): ?Ticket
    {
        $model = Ticketing::ticketModel();

        return $model::query()->withoutTenancy()->find($clock->ticket_id);
    }

    protected function minutesFor(SlaPolicy $policy, SlaTarget $target): ?int
    {
        return match ($target) {
            SlaTarget::FirstResponse => $policy->first_response_minutes,
            SlaTarget::NextResponse => $policy->next_response_minutes,
            SlaTarget::Resolution => $policy->resolution_minutes,
        };
    }
}
