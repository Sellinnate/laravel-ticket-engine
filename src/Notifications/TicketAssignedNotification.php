<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications;

/**
 * Sent to the new assignee when a ticket is assigned to them.
 */
class TicketAssignedNotification extends TicketNotification
{
    public function key(): string
    {
        return 'ticket.assigned';
    }

    public function title(): string
    {
        return 'Ticket assigned: '.$this->ticket->title;
    }

    public function body(): string
    {
        return 'Ticket '.$this->ticket->reference.' has been assigned to you.';
    }
}
