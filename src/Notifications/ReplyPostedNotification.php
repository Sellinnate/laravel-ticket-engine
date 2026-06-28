<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications;

use Illuminate\Support\Str;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;

/**
 * Sent to a ticket's participants when a new message is posted.
 */
class ReplyPostedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public TicketMessage $message)
    {
        parent::__construct($ticket);
    }

    public function key(): string
    {
        return 'ticket.reply';
    }

    public function title(): string
    {
        return 'New reply on '.$this->ticket->reference;
    }

    public function body(): string
    {
        return Str::limit((string) $this->message->body, 140);
    }
}
