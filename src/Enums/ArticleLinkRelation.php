<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * How a knowledge-base article relates to a ticket.
 */
enum ArticleLinkRelation: string
{
    /** The ticket was resolved with this article. */
    case ResolvedBy = 'resolved_by';

    /** The article was suggested (by deflection or by the AI). */
    case Suggested = 'suggested';

    /** Any other reference an agent recorded. */
    case Linked = 'linked';
}
