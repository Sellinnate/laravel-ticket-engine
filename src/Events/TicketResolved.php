<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted the first time a ticket reaches a resolved (closed-semantic) state.
 * The hook for requesting CSAT and stopping the resolution SLA clock.
 */
class TicketResolved
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public ?Model $actor = null,
    ) {}
}
