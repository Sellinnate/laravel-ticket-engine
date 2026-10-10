<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * The tone of a customer message as classified by the AI.
 */
enum Sentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
}
