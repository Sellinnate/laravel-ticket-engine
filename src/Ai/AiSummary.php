<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

/**
 * A short summary of a conversation for the agent who takes over.
 */
final readonly class AiSummary
{
    public function __construct(
        public string $body,
        public AiUsage $usage = new AiUsage,
    ) {}
}
