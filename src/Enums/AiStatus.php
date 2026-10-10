<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * Where a ticket stands with respect to the AI. Null on the ticket means the AI
 * was never involved.
 */
enum AiStatus: string
{
    /** The AI is handling the ticket. */
    case Active = 'active';

    /** The AI handed the ticket to humans; it no longer answers. */
    case Escalated = 'escalated';

    /** A human took over; the AI never writes on this ticket again. */
    case Suspended = 'suspended';

    /** The ticket was resolved while the AI was handling it, without a human. */
    case Resolved = 'resolved';

    /** Whether the AI may still answer the customer on this ticket. */
    public function answers(): bool
    {
        return $this === self::Active;
    }
}
