<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted when a ticket leaves a resolved/terminal state back into an open one.
 */
class TicketReopened
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public string $from,
        public string $to,
        public ?Model $actor = null,
    ) {}
}
