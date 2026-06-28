<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted when a ticket's assignee and/or team changes.
 */
class TicketAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public ?Model $assignee = null,
        public ?Team $team = null,
        public ?Model $actor = null,
    ) {}
}
