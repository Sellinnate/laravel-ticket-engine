<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket and every row it owned were permanently deleted (retention). Only
 * scalars are carried — the model no longer exists. Integrations holding
 * copies (search or vector indexes) listen to this to purge them.
 */
class TicketErased implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int|string $ticketId,
        public int|string|null $tenantId,
    ) {}
}
