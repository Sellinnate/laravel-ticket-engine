<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketParticipant;

/**
 * Emitted when a participant is added to a ticket.
 */
class ParticipantAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public TicketParticipant $participant,
    ) {}
}
