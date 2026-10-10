<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai\Drivers;

use RuntimeException;
use Selli\Ticketing\Ai\AiAnswer;
use Selli\Ticketing\Ai\AiClassification;
use Selli\Ticketing\Ai\AiRequest;
use Selli\Ticketing\Ai\AiSource;
use Selli\Ticketing\Ai\AiSummary;
use Selli\Ticketing\Ai\AiUsage;
use Selli\Ticketing\Contracts\AiDriver;
use Selli\Ticketing\Enums\Sentiment;
use Selli\Ticketing\Knowledge\KnowledgeBase;
use Selli\Ticketing\Knowledge\KnowledgeResult;

/**
 * A deterministic driver for tests, demos and local development — no network.
 *
 * - classify(): keyword heuristics (asks for a human, negative tone, a few
 *   sensitive topics);
 * - answer(): the best knowledge-base match for the request's audience, with
 *   its keyword score as confidence;
 * - summarize(): the first lines of the conversation.
 *
 * Tests can script it: queueAnswer() / queueClassification() / failNext(),
 * and inspect requests().
 */
class FakeAiDriver implements AiDriver
{
    /** @var list<AiAnswer> */
    protected array $answers = [];

    /** @var list<AiClassification> */
    protected array $classifications = [];

    protected ?string $failure = null;

    /** @var list<array{operation: string, request: AiRequest}> */
    protected array $requests = [];

    public function __construct(protected KnowledgeBase $knowledge) {}

    public function queueAnswer(AiAnswer $answer): static
    {
        $this->answers[] = $answer;

        return $this;
    }

    public function queueClassification(AiClassification $classification): static
    {
        $this->classifications[] = $classification;

        return $this;
    }

    /**
     * Make the next call throw, like a provider outage.
     */
    public function failNext(string $message = 'AI provider unavailable'): static
    {
        $this->failure = $message;

        return $this;
    }

    /**
     * @return list<array{operation: string, request: AiRequest}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function classify(AiRequest $request): AiClassification
    {
        $this->record('classify', $request);

        if ($this->classifications !== []) {
            return array_shift($this->classifications);
        }

        $text = mb_strtolower($request->question);

        $topics = [];
        foreach ([
            'billing' => ['invoice', 'refund', 'charge', 'payment', 'billing', 'fattura', 'rimborso'],
            'legal' => ['lawyer', 'legal', 'lawsuit', 'avvocato'],
            'privacy' => ['gdpr', 'privacy', 'personal data'],
            'complaint' => ['complaint', 'reclamo'],
        ] as $topic => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    $topics[] = $topic;

                    break;
                }
            }
        }

        $negative = preg_match('/\b(angry|terrible|worst|furious|unacceptable|pessimo|arrabbiat\w*|vergogna)\b/u', $text) === 1;

        return new AiClassification(
            language: $request->locale,
            sentiment: $negative ? Sentiment::Negative : Sentiment::Neutral,
            wantsHuman: preg_match('/\b(human|person|agent|operator|someone real|operatore|persona)\b/u', $text) === 1,
            topics: $topics,
            usage: $this->usage($request->question, ''),
        );
    }

    public function answer(AiRequest $request): AiAnswer
    {
        $this->record('answer', $request);

        if ($this->answers !== []) {
            return array_shift($this->answers);
        }

        $results = $this->knowledge->search($request->question, $request->audience, $request->locale, 3);

        if ($results === []) {
            return AiAnswer::none($this->usage($request->question, ''));
        }

        $best = $results[0];
        $body = trim(($best->article->summary ?? '') !== '' ? (string) $best->article->summary : $best->excerpt);

        return new AiAnswer(
            body: $body,
            sources: array_map(fn (KnowledgeResult $result): AiSource => new AiSource(
                type: $result->article->type->value === 'faq' ? 'faq' : 'kb_article',
                id: (string) $result->article->getKey(),
                title: $result->article->title,
                score: $result->score,
                excerpt: $result->excerpt,
            ), $results),
            confidence: $best->score,
            usage: $this->usage($request->question, $body),
        );
    }

    public function summarize(AiRequest $request): AiSummary
    {
        $this->record('summarize', $request);

        $lines = [];
        foreach (array_slice($request->history, 0, 5) as $message) {
            $lines[] = ucfirst($message->role).': '.mb_strimwidth(trim($message->body), 0, 120, '…');
        }

        $body = $lines === [] ? 'Customer: '.mb_strimwidth($request->question, 0, 200, '…') : implode("\n", $lines);

        return new AiSummary($body, $this->usage($request->question, $body));
    }

    protected function record(string $operation, AiRequest $request): void
    {
        $this->requests[] = ['operation' => $operation, 'request' => $request];

        if ($this->failure !== null) {
            $message = $this->failure;
            $this->failure = null;

            throw new RuntimeException($message);
        }
    }

    /**
     * Rough token estimate (4 characters per token), like most providers' rule of thumb.
     */
    protected function usage(string $input, string $output): AiUsage
    {
        return new AiUsage(
            model: 'fake',
            inputTokens: (int) ceil(mb_strlen($input) / 4),
            outputTokens: (int) ceil(mb_strlen($output) / 4),
        );
    }
}
