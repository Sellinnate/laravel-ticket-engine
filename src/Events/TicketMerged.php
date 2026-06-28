<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted after one or more source tickets are merged into a target ticket.
 */
class TicketMerged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int|string>  $sourceIds
     */
    public function __construct(
        public Ticket $target,
        public array $sourceIds,
        public ?Model $actor = null,
    ) {}
}
