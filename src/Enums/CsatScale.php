<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * The satisfaction scale a rating is recorded against. The numeric `rating`
 * stored on a SatisfactionRating is always interpreted through its scale, so an
 * app can switch scales without losing the meaning of historical data.
 */
enum CsatScale: string
{
    case Thumbs = 'thumbs';       // 0 = down, 1 = up
    case FiveStar = 'five_star';  // 1..5
    case Nps = 'nps';             // 0..10

    public function min(): int
    {
        return match ($this) {
            self::Thumbs, self::Nps => 0,
            self::FiveStar => 1,
        };
    }

    public function max(): int
    {
        return match ($this) {
            self::Thumbs => 1,
            self::FiveStar => 5,
            self::Nps => 10,
        };
    }

    public function accepts(int $rating): bool
    {
        return $rating >= $this->min() && $rating <= $this->max();
    }

    /**
     * Normalise a rating to a 0..1 satisfaction fraction, so aggregates can mix
     * scales (e.g. an average "positive" score) if an app ever changes scale.
     */
    public function fraction(int $rating): float
    {
        $span = $this->max() - $this->min();

        if ($span === 0) {
            return (float) $rating;
        }

        return ($rating - $this->min()) / $span;
    }

    /**
     * Whether the rating counts as a "satisfied"/promoter response: thumbs up,
     * >= 4 of 5, or — following the NPS convention — only 9-10 (promoters), so a
     * passive 7-8 is not miscounted as positive.
     */
    public function isPositive(int $rating): bool
    {
        if (! $this->accepts($rating)) {
            return false;
        }

        return match ($this) {
            self::Nps => $rating >= 9,
            default => $this->fraction($rating) >= 0.6,
        };
    }
}
