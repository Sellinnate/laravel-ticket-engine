<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted when a ticket's priority changes (manually or via automation).
 */
class PriorityChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public Priority $from,
        public Priority $to,
        public ?Model $actor = null,
    ) {}
}
