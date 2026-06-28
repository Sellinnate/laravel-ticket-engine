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
            $this->writeClock($ticket, SlaTarget::FirstResponse, $now, $calendar->addMinutes($now, $policy->first_response_minutes));
        }

        if ($policy->resolution_minutes !== null) {
            $this->writeClock($ticket, SlaTarget::Resolution, $now, $calendar->addMinutes($now, $policy->resolution_minutes));
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

        $this->writeClock($ticket, SlaTarget::Resolution, $now, $calendar->addMinutes($now, $policy->resolution_minutes));
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

        if ($policy === null) {
            return;
        }

        $calendar = $this->calendars->forPolicy($policy);
        $model = Ticketing::slaClockModel();

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
                continue;
            }

            // Recompute the deadline and clear stale alert state so the next
            // sweep re-evaluates against the new deadline.
            $clock->forceFill([
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
            return;
        }

        if ($clock->breached_at === null && $clock->due_at->lessThanOrEqualTo($now)) {
            $clock->forceFill(['breached_at' => $now])->save();
            SlaBreached::dispatch($ticket, $clock);

            return;
        }

        // Don't emit a threshold warning once the clock has already breached.
        if (! $clock->threshold_notified && $clock->breached_at === null
            && $this->fractionConsumed($clock, $now) >= $thresholdPercent / 100) {
            $clock->forceFill(['threshold_notified' => true])->save();
            SlaThresholdReached::dispatch($ticket, $clock, $thresholdPercent);
        }
    }

    protected function fractionConsumed(SlaClock $clock, Carbon $now): float
    {
        $policy = null;
        $ticket = $this->loadTicket($clock);

        if ($ticket !== null) {
            $policy = $this->policies->resolve($ticket);
        }

        $calendar = $this->calendars->forPolicy($policy);

        $consumed = $calendar->minutesBetween($clock->started_at, $now);
        $remaining = $calendar->minutesBetween($now, $clock->due_at);
        $budget = $consumed + $remaining;

        return $budget > 0 ? $consumed / $budget : 1.0;
    }

    protected function pauseClocks(Ticket $ticket): void
    {
        $policy = $this->policies->resolve($ticket);
        $calendar = $this->calendars->forPolicy($policy);
        $now = now();

        foreach ($this->runningClocks($ticket) as $clock) {
            $remaining = $calendar->minutesBetween($now, $clock->due_at);
            $clock->forceFill(['paused_at' => $now, 'remaining_minutes' => $remaining])->save();
        }
    }

    protected function resumeClocks(Ticket $ticket): void
    {
        $policy = $this->policies->resolve($ticket);
        $calendar = $this->calendars->forPolicy($policy);
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
                'due_at' => $calendar->addMinutes($now, (int) $clock->remaining_minutes),
                'paused_at' => null,
                'remaining_minutes' => null,
            ])->save();
        }
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

    protected function writeClock(Ticket $ticket, SlaTarget $target, CarbonInterface $startedAt, CarbonInterface $dueAt): void
    {
        $model = Ticketing::slaClockModel();

        $model::query()->withoutTenancy()->updateOrCreate(
            array_merge($ticket->tenantAttributes(), [
                'ticket_id' => $ticket->getKey(),
                'target' => $target->value,
            ]),
            [
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
