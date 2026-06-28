<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

use Selli\Ticketing\Enums\CsatScale;

/**
 * Raised when a CSAT request or submission is invalid.
 */
class CsatException extends TicketingException
{
    public static function invalidRating(int $rating, CsatScale $scale): self
    {
        return new self("Rating [{$rating}] is outside the [{$scale->value}] scale ({$scale->min()}..{$scale->max()}).");
    }

    public static function invalidToken(): self
    {
        return new self('The CSAT token is invalid or has expired.');
    }

    public static function disabled(): self
    {
        return new self('CSAT is disabled in the ticketing configuration.');
    }
}
