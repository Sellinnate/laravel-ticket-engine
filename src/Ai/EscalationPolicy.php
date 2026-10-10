<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Enums\Sentiment;

/**
 * The configurable rules that hand a conversation to humans
 * (`ticketing.ai.escalation.*`). Checked twice per turn: on the classification
 * (before spending an answer) and on the answer.
 *
 * @internal
 */
class EscalationPolicy
{
    /**
     * A reason to escalate before answering, or null to let the AI answer.
     */
    public function beforeAnswer(AiClassification $classification, int $exchanges): ?EscalationReason
    {
        // Always on: a customer who asks for a person gets one.
        if ($classification->wantsHuman) {
            return EscalationReason::HumanRequested;
        }

        $sensitive = array_map('strtolower', array_map('strval', (array) config('ticketing.ai.escalation.sensitive_topics', [])));

        if (array_intersect(array_map('strtolower', $classification->topics), $sensitive) !== []) {
            return EscalationReason::SensitiveTopic;
        }

        if (config('ticketing.ai.escalation.on_negative_sentiment', false) === true
            && $classification->sentiment === Sentiment::Negative) {
            return EscalationReason::NegativeSentiment;
        }

        if ($this->exchangesExhausted($exchanges)) {
            return EscalationReason::TooManyExchanges;
        }

        return null;
    }

    /**
     * Whether the AI already gave its last allowed answer (re-checked under
     * the row lock, where concurrent turns serialise).
     */
    public function exchangesExhausted(int $exchanges): bool
    {
        $max = (int) config('ticketing.ai.escalation.max_exchanges', 3);

        return $max > 0 && $exchanges >= $max;
    }

    /**
     * A reason to escalate instead of sending this answer, or null.
     */
    public function afterAnswer(AiAnswer $answer): ?EscalationReason
    {
        if ($answer->wantsHuman) {
            return EscalationReason::HumanRequested;
        }

        if ($answer->isEmpty()) {
            return EscalationReason::NoSources;
        }

        if (config('ticketing.ai.escalation.require_sources', true) !== false && $answer->sources === []) {
            return EscalationReason::NoSources;
        }

        $min = (float) config('ticketing.ai.escalation.min_confidence', 0.7);

        if ($answer->confidence < $min) {
            return EscalationReason::LowConfidence;
        }

        return null;
    }
}
