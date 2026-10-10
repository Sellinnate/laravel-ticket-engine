<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * Why a ticket or conversation was handed to humans.
 */
enum EscalationReason: string
{
    case LowConfidence = 'low_confidence';
    case NoSources = 'no_sources';
    case HumanRequested = 'human_requested';
    case SensitiveTopic = 'sensitive_topic';
    case TooManyExchanges = 'too_many_exchanges';
    case NegativeSentiment = 'negative_sentiment';
    case AiError = 'ai_error';

    /** Escalated explicitly by code or an agent, not by a rule. */
    case Manual = 'manual';

    /**
     * Reasons that point at a gap in the knowledge base (the AI did not know).
     *
     * @return list<self>
     */
    public static function knowledgeGaps(): array
    {
        return [self::NoSources, self::LowConfidence];
    }
}
