<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * How much the AI may do for a ticket type / tenant.
 */
enum AiMode: string
{
    /** The AI answers the customer directly and escalates when unsure. */
    case Auto = 'auto';

    /** The AI writes an internal draft; an agent sends (or rewrites) it. */
    case Draft = 'draft';

    /** No AI involvement. */
    case Off = 'off';
}
