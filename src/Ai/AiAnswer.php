<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

/**
 * The AI's reply: the body, the sources it used, its confidence (0..1) and the
 * usage. `wantsHuman` lets a driver hand over by itself (e.g. the model noticed
 * the customer asking for a person).
 */
final readonly class AiAnswer
{
    /**
     * @param  list<AiSource>  $sources
     */
    public function __construct(
        public string $body,
        public array $sources = [],
        public float $confidence = 0.0,
        public AiUsage $usage = new AiUsage,
        public bool $wantsHuman = false,
    ) {}

    public static function none(?AiUsage $usage = null): self
    {
        return new self('', [], 0.0, $usage ?? AiUsage::none());
    }

    public function isEmpty(): bool
    {
        return trim($this->body) === '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sourcesArray(): array
    {
        return array_map(fn (AiSource $source): array => $source->toArray(), $this->sources);
    }
}
