<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted after messages are split out of a ticket into a new one.
 */
class TicketSplit implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Ticket $source,
        public Ticket $created,
        public ?Model $actor = null,
    ) {}
}
