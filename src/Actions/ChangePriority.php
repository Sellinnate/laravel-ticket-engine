<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Events\PriorityChanged;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;

/**
 * Changes a ticket's priority under a row lock, records it in the audit trail and
 * emits {@see PriorityChanged} (a no-op when the priority is unchanged).
 */
class ChangePriority
{
    public function __construct(protected AuditLogger $audit) {}

    public function handle(Ticket $ticket, Priority $priority, ?Model $actor = null): Ticket
    {
        $result = DB::transaction(function () use ($ticket, $priority, $actor): array {
            /** @var Ticket $locked */
            $locked = Ticketing::ticketModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->findOrFail($ticket->getKey());

            $from = $locked->priority;

            if ($from === $priority) {
                return [$locked, $from, false];
            }

            $locked->forceFill(['priority' => $priority->value])->save();

            $this->audit->record($locked, 'ticket.priority_changed', $actor, changes: [
                'priority' => ['from' => $from->value, 'to' => $priority->value],
            ]);

            return [$locked, $from, true];
        });

        /** @var array{0: Ticket, 1: Priority, 2: bool} $result */
        [$ticket, $from, $changed] = $result;

        if ($changed) {
            PriorityChanged::dispatch($ticket, $from, $priority, $actor);
        }

        return $ticket;
    }
}
