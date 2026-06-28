<?php

declare(strict_types=1);

namespace Selli\Ticketing\Sla;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Enums\SlaTarget;
use Selli\Ticketing\Events\SlaBreached;
use Selli\Ticketing\Events\SlaThresholdReached;
use Selli\Ticketing\Models\SlaClock;
use Selli\Ticketing\Models\SlaPolicy;
use Selli\Ticketing\Models\Ticket;
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

        if ($policy->first_response_minutes !== null && $ticket->first_response_at === null) {
            $this->writeClock($ticket, SlaTarget::FirstResponse, $now, $calendar->addMinutes($now, $policy->first_response_minutes), $policy->first_response_minutes, $policy->business_hours_id);
        }

        if ($policy->resolution_minutes !== null) {
            $this->writeClock($ticket, SlaTarget::Resolution, $now, $calendar->addMinutes($now, $policy->resolution_minutes), $policy->resolution_minutes, $policy->business_hours_id);
        }
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
     * Pause or resume the clocks based on whether the new state is a pause state.
     */
    public function syncForTransition(Ticket $ticket, string $from, string $to): void
    {
        $pauseStates = $this->pauseStates($ticket);

        $wasPaused = in_array($from, $pauseStates, true);
        $isPaused = in_array($to, $pauseStates, true);

        if ($isPaused && ! $wasPaused) {
            $this->pauseClocks($ticket);
        } elseif ($wasPaused && ! $isPaused) {
            $this->resumeClocks($ticket);
        }
    }

    /**
     * Complete the resolution clock on resolution.
     */
    public function handleResolved(Ticket $ticket): void
    {
        $clock = $this->clock($ticket, SlaTarget::Resolution);

        if ($clock !== null && ! $clock->isCompleted()) {
            $clock->forceFill(['completed_at' => now(), 'paused_at' => null])->save();
        }
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
            ->orderBy('due_at')
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

        // Only running clocks are recalculated: paused clocks are frozen and are
        // recomputed from their captured budget on resume, so overwriting their
        // due_at here would desync them from remaining_minutes.
        $clocks = $model::query()
            ->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->whereNull('completed_at')
            ->whereNull('paused_at')
            ->get();

        foreach ($clocks as $clock) {
            $minutes = $this->minutesFor($policy, $clock->target);

            if ($minutes === null) {
                // The target was removed from the policy — stop the timer so it
                // no longer sweeps against a target that no longer exists.
                $clock->forceFill(['completed_at' => now(), 'paused_at' => null])->save();

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
            // Atomically claim the breach so two concurrent sweeps emit it once.
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
     * Atomically set $values on a clock only if $guardColumn still holds
     * $guardIsNull's sentinel (null, or the given falsey value). Returns true
     * when this caller won the claim.
     *
     * @param  array<string, mixed>  $values
     */
    protected function claim(SlaClock $clock, array $values, string $guardColumn, mixed $expected = null): bool
    {
        $model = Ticketing::slaClockModel();

        $query = $model::query()->withoutTenancy()->whereKey($clock->getKey());

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
