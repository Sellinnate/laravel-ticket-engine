<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * What became of an inbound email.
 */
enum InboundOutcome: string
{
    /** Recorded on a new or an existing ticket. */
    case Ingested = 'ingested';

    /** Already ingested (the same Message-ID). */
    case Duplicate = 'duplicate';

    /** Automated mail (auto-reply, bounce, no-reply, our own): dropped for good. */
    case Ignored = 'ignored';

    /** The recipient routes nowhere, or the ticket type is invalid: dropped for good. */
    case Unroutable = 'unroutable';

    /** Over the sender's rate limit: nothing recorded, worth retrying later. */
    case RateLimited = 'rate_limited';

    /** Inbound email is disabled. */
    case Disabled = 'disabled';

    /** Whether the same email should be offered again later. */
    public function retryable(): bool
    {
        return $this === self::RateLimited || $this === self::Disabled;
    }
}
