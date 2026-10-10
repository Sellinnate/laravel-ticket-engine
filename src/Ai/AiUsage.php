<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

/**
 * Tokens and cost of one or more AI calls. Drivers that cannot measure tokens
 * return estimates (and should say so in their documentation).
 */
final readonly class AiUsage
{
    public function __construct(
        public ?string $model = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $cost = 0.0,
        public ?string $currency = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function plus(self $other): self
    {
        return new self(
            model: $other->model ?? $this->model,
            inputTokens: $this->inputTokens + $other->inputTokens,
            outputTokens: $this->outputTokens + $other->outputTokens,
            cost: $this->cost + $other->cost,
            currency: $other->currency ?? $this->currency,
        );
    }
}
