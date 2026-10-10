<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications;

use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Models\Ticket;

/**
 * Sent to the assignee and the team leads when a ticket is handed to humans.
 * Carries the reference and the reason, never the conversation itself.
 *
 * @internal
 */
class TicketEscalatedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public EscalationReason $reason)
    {
        parent::__construct($ticket);
    }

    public function key(): string
    {
        return 'ticket.escalated';
    }

    public function title(): string
    {
        return 'Needs a human: '.$this->ticket->reference;
    }

    public function body(): string
    {
        return 'Ticket '.$this->ticket->reference.' was handed over to the team ('.str_replace('_', ' ', $this->reason->value).').';
    }

    public function toArray(object $notifiable): array
    {
        return array_merge(parent::toArray($notifiable), ['reason' => $this->reason->value]);
    }
}
