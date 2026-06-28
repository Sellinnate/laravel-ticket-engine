<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketActivity;

/**
 * Writes append-only entries to the audit trail. The only sanctioned way to add
 * to `ticket_activities`.
 */
class AuditLogger
{
    /**
     * Record an activity against a ticket.
     *
     * @param  array<string, mixed>  $changes  before/after diffs
     * @param  array<string, mixed>  $context  arbitrary contextual data
     */
    public function record(
        Ticket $ticket,
        string $event,
        ?Model $actor = null,
        array $changes = [],
        array $context = [],
    ): TicketActivity {
        $model = Ticketing::ticketActivityModel();

        // Inherit the ticket's tenant so system/CLI writes are attributed
        // correctly even without a resolved context.
        $attributes = array_merge($ticket->tenantAttributes(), [
            'ticket_id' => $ticket->getKey(),
            'event' => $event,
            'changes' => $changes === [] ? null : $changes,
            'context' => $context === [] ? null : $context,
        ]);

        if ($actor !== null) {
            $attributes['actor_type'] = $actor->getMorphClass();
            $attributes['actor_id'] = $actor->getKey();
        }

        return $model::query()->create($attributes);
    }
}
