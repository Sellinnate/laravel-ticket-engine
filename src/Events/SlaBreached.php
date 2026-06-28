<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Selli\Ticketing\Models\SlaClock;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted when an SLA target's deadline passes without completion. The hook for
 * escalations and external alerting (PagerDuty, Slack, …).
 */
class SlaBreached implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public SlaClock $clock,
    ) {}
}
