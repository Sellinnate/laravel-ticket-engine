<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Models\SatisfactionRating;
use Selli\Ticketing\Models\Ticket;

/**
 * Emitted when CSAT is requested for a (resolved) ticket. The host app listens
 * here to mail/notify the requester a link to the rating page, built from the
 * signed {@see $token} (which the host embeds in its own URL).
 */
class CsatRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Ticket $ticket,
        public SatisfactionRating $rating,
        public string $token,
        public Carbon $expiresAt,
    ) {}
}
