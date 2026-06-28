<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\SatisfactionRating;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted when a requester submits (or updates) a satisfaction rating. The hook
 * for thanking the requester, updating dashboards, or triggering follow-up on a
 * low score.
 */
class CsatSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public SatisfactionRating $rating,
    ) {}
}
