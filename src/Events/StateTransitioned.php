<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted on every state transition. Everything that must happen "when a ticket
 * becomes resolved" (stop the SLA clock, notify the requester, request CSAT) is
 * a listener on this event, not logic nested in the transition.
 */
class StateTransitioned
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public string $transition,
        public string $from,
        public string $to,
        public ?Model $actor = null,
        public ?string $note = null,
    ) {}
}
