<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Contracts\TransitionGuard;
use Selli\Ticketing\Data\TransitionData;
use Selli\Ticketing\Events\StateTransitioned;
use Selli\Ticketing\Events\TicketClosed;
use Selli\Ticketing\Events\TicketReopened;
use Selli\Ticketing\Events\TicketResolved;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Exceptions\TransitionNotAllowedException;
use Selli\Ticketing\Exceptions\UnknownTransitionException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Workflow\TransitionContext;
use Selli\Ticketing\Workflow\WorkflowManager;

/**
 * Applies a workflow transition to a ticket: validates it is permitted from the
 * current state, runs guards, updates the status and the derived lifecycle
 * timestamps, writes the audit entry and emits the lifecycle events. The
 * transition stays declarative — side effects live in listeners on the events.
 */
class TransitionTicket
{
    public function __construct(
        protected WorkflowManager $workflow,
        protected AuditLogger $audit,
        protected Container $container,
    ) {}

    public function handle(TransitionData $data): Ticket
    {
        $workflow = $this->resolveWorkflow($data->ticket);
        $driver = $this->workflow->driver();

        // Validate, lock and apply atomically. Locking and re-reading the row
        // inside the transaction closes the check-then-act race: a concurrent
        // transition cannot pass its checks against a now-stale status.
        $result = DB::transaction(function () use ($data, $workflow, $driver): array {
            $ticket = $this->lockTicket($data->ticket->getKey());
            $from = $ticket->status;

            if (! $driver->hasTransition($workflow, $data->transition)) {
                throw UnknownTransitionException::make($workflow, $data->transition);
            }

            if (! $driver->canApply($workflow, $from, $data->transition)) {
                throw TransitionNotAllowedException::invalidFromState($data->transition, $from);
            }

            $transition = $driver->resolveTransition($workflow, $data->transition);
            $to = $transition->to;

            $this->runGuards($transition->guards, new TransitionContext(
                ticket: $ticket,
                transition: $data->transition,
                from: $from,
                to: $to,
                actor: $data->actor,
                note: $data->note,
                params: $data->params,
            ));

            $wasClosed = $driver->matchesSemantic($workflow, $from, 'closed');
            $isClosed = $driver->matchesSemantic($workflow, $to, 'closed');
            $isOpen = $driver->matchesSemantic($workflow, $to, 'open');
            $isTerminal = $driver->isTerminal($workflow, $to);

            // A reopen is specifically leaving a resolved/closed state back into
            // an open one — not, say, closed -> paused.
            $reopening = $wasClosed && $isOpen;
            $justResolved = false;
            $justClosed = false;

            $ticket->status = $to;

            if ($reopening) {
                $ticket->reopened_count = (int) $ticket->reopened_count + 1;
                $ticket->resolved_at = null;
                $ticket->closed_at = null;
            }

            if ($isClosed && $ticket->resolved_at === null) {
                $ticket->resolved_at = now();
                $justResolved = true;
            }

            if ($isTerminal && $ticket->closed_at === null) {
                $ticket->closed_at = now();
                $justClosed = true;
            }

            $ticket->save();

            $this->audit->record(
                ticket: $ticket,
                event: 'ticket.transitioned',
                actor: $data->actor,
                changes: ['status' => ['from' => $from, 'to' => $to]],
                context: array_filter([
                    'transition' => $data->transition,
                    'note' => $data->note,
                ], fn ($value): bool => $value !== null),
            );

            return compact('ticket', 'from', 'to', 'reopening', 'justResolved', 'justClosed');
        });

        /** @var Ticket $ticket */
        $ticket = $result['ticket'];

        StateTransitioned::dispatch($ticket, $data->transition, $result['from'], $result['to'], $data->actor, $data->note);

        if ($result['reopening']) {
            TicketReopened::dispatch($ticket, $result['from'], $result['to'], $data->actor);
        }

        if ($result['justResolved']) {
            TicketResolved::dispatch($ticket, $data->actor);
        }

        if ($result['justClosed']) {
            TicketClosed::dispatch($ticket, $data->actor);
        }

        return $ticket;
    }

    /**
     * Fetch the ticket for update under a row lock (scope-independent — the key
     * already identifies the row).
     */
    protected function lockTicket(int|string $key): Ticket
    {
        $model = Ticketing::ticketModel();

        /** @var Ticket $ticket */
        $ticket = $model::query()
            ->withoutTenancy()
            ->lockForUpdate()
            ->findOrFail($key);

        return $ticket;
    }

    /**
     * @param  list<class-string<TransitionGuard>>  $guards
     */
    protected function runGuards(array $guards, TransitionContext $context): void
    {
        foreach ($guards as $guardClass) {
            $guard = $this->container->make($guardClass);

            if (! $guard instanceof TransitionGuard) {
                // A misconfigured guard must never silently allow the transition.
                throw new InvalidConfigurationException(sprintf(
                    'Transition guard [%s] must implement %s.',
                    is_object($guard) ? $guard::class : (string) $guardClass,
                    TransitionGuard::class,
                ));
            }

            if (! $guard->allows($context)) {
                throw TransitionNotAllowedException::guardDenied($context->transition, $guard->deniedMessage());
            }
        }
    }

    protected function resolveWorkflow(Ticket $ticket): string
    {
        $typeModel = Ticketing::ticketTypeModel();

        $type = $typeModel::query()
            ->withoutTenancy()
            ->whereKey($ticket->ticket_type_id)
            ->first();

        $workflow = $type?->workflow;

        return is_string($workflow) && $workflow !== '' ? $workflow : 'default';
    }
}
