<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;

/**
 * Emitted after a message is posted to a ticket.
 */
class MessagePosted
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public TicketMessage $message,
    ) {}
}
