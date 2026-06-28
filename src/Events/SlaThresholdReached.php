<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\SlaClock;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted when an SLA timer crosses a warning threshold (e.g. 75% of the budget
 * consumed) before breaching.
 */
class SlaThresholdReached implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public SlaClock $clock,
        public int $thresholdPercent,
    ) {}
}
