<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted when a ticket enters a terminal state.
 */
class TicketClosed
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public ?Model $actor = null,
    ) {}
}
