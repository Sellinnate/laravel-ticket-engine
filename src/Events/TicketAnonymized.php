<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Retention anonymised a ticket: it stays for the statistics, its personal
 * data is gone. Integrations holding copies (search or vector indexes) listen
 * to this to drop or re-index theirs.
 */
class TicketAnonymized implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int|string $ticketId,
        public int|string|null $tenantId,
    ) {}
}
