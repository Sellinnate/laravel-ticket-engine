<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications;

use Selli\Ticketing\Models\Ticket;

/**
 * Sent to an actor when they're added to a ticket (e.g. via an @mention).
 */
class ParticipantAddedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public string $role)
    {
        parent::__construct($ticket);
    }

    public function key(): string
    {
        return 'ticket.participant_added';
    }

    public function title(): string
    {
        return 'Added to ticket '.$this->ticket->reference;
    }

    public function body(): string
    {
        return 'You were added to ticket '.$this->ticket->reference.' as '.$this->role.'.';
    }
}
