<?php

declare(strict_types=1);

use Selli\Ticketing\Ai\AiAnswer;
use Selli\Ticketing\Ai\AiClassification;
use Selli\Ticketing\Ai\AiSource;
use Selli\Ticketing\Ai\AiUsage;
use Selli\Ticketing\Ai\ConfigAiSettings;
use Selli\Ticketing\Ai\EscalationPolicy;
use Selli\Ticketing\Enums\AiMode;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Enums\Sentiment;

it('resolves the AI mode most-specific first, off unless enabled', function (): void {
    $settings = new ConfigAiSettings;

    config()->set('ticketing.ai.enabled', false);
    config()->set('ticketing.ai.mode', 'auto');
    expect($settings->mode(1, 'support', 'web'))->toBe(AiMode::Off);

    config()->set('ticketing.ai.enabled', true);
    config()->set('ticketing.ai.modes', [
        'types' => ['incident' => 'draft'],
        'tenants' => [
            5 => 'off',
            6 => ['mode' => 'draft', 'types' => ['support' => 'auto']],
            7 => ['types' => ['support' => 'off']],
        ],
    ]);

    expect($settings->mode(1, 'support', 'web'))->toBe(AiMode::Auto)
        ->and($settings->mode(1, 'incident', 'web'))->toBe(AiMode::Draft)
        ->and($settings->mode(5, 'incident', 'web'))->toBe(AiMode::Off)
        ->and($settings->mode(6, 'support', 'web'))->toBe(AiMode::Auto)
        ->and($settings->mode(6, 'incident', 'web'))->toBe(AiMode::Draft)
        ->and($settings->mode(7, 'support', 'web'))->toBe(AiMode::Off)
        ->and($settings->mode(7, 'incident', 'web'))->toBe(AiMode::Draft)
        ->and($settings->mode(null, null, null))->toBe(AiMode::Auto);

    // A typo fails closed.
    config()->set('ticketing.ai.mode', 'automatic');
    config()->set('ticketing.ai.modes', []);
    expect($settings->mode(1, 'support', 'web'))->toBe(AiMode::Off);
});

it('applies the escalation rules before and after the answer', function (): void {
    $policy = new EscalationPolicy;
    config()->set('ticketing.ai.escalation', [
        'min_confidence' => 0.7,
        'require_sources' => true,
        'sensitive_topics' => ['legal'],
        'max_exchanges' => 3,
        'on_negative_sentiment' => true,
    ]);

    $sourced = fn (float $confidence): AiAnswer => new AiAnswer('Answer', [new AiSource('faq', '1', 'F')], $confidence);

    expect($policy->beforeAnswer(AiClassification::none(), 0))->toBeNull()
        ->and($policy->beforeAnswer(new AiClassification(wantsHuman: true, topics: ['legal']), 0))->toBe(EscalationReason::HumanRequested)
        ->and($policy->beforeAnswer(new AiClassification(topics: ['LEGAL']), 0))->toBe(EscalationReason::SensitiveTopic)
        ->and($policy->beforeAnswer(new AiClassification(sentiment: Sentiment::Negative), 0))->toBe(EscalationReason::NegativeSentiment)
        ->and($policy->beforeAnswer(AiClassification::none(), 3))->toBe(EscalationReason::TooManyExchanges)
        ->and($policy->afterAnswer($sourced(0.9)))->toBeNull()
        ->and($policy->afterAnswer($sourced(0.69)))->toBe(EscalationReason::LowConfidence)
        ->and($policy->afterAnswer(AiAnswer::none()))->toBe(EscalationReason::NoSources)
        ->and($policy->afterAnswer(new AiAnswer('Unsourced', [], 0.99)))->toBe(EscalationReason::NoSources);

    config()->set('ticketing.ai.escalation.require_sources', false);
    config()->set('ticketing.ai.escalation.max_exchanges', 0);
    expect($policy->afterAnswer(new AiAnswer('Unsourced', [], 0.99)))->toBeNull()
        ->and($policy->beforeAnswer(AiClassification::none(), 99))->toBeNull();
});

it('adds usage and knows which statuses still answer', function (): void {
    $total = (new AiUsage('a', 10, 5, 0.1, 'EUR'))->plus(new AiUsage(null, 1, 2, 0.05));

    expect($total->inputTokens)->toBe(11)
        ->and($total->outputTokens)->toBe(7)
        ->and(round($total->cost, 2))->toBe(0.15)
        ->and($total->model)->toBe('a')
        ->and($total->currency)->toBe('EUR')
        ->and(AiStatus::Active->answers())->toBeTrue()
        ->and(AiStatus::Escalated->answers())->toBeFalse()
        ->and(EscalationReason::knowledgeGaps())->toBe([EscalationReason::NoSources, EscalationReason::LowConfidence])
        ->and((new AiSource('faq', '1', 'F', 0.123456))->toArray()['score'])->toBe(0.1235);
});
