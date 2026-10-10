<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

/**
 * One turn of conversation history handed to the AI.
 */
final readonly class AiMessage
{
    public const CUSTOMER = 'customer';

    public const AGENT = 'agent';

    public const ASSISTANT = 'assistant';

    public function __construct(
        public string $role,
        public string $body,
    ) {}
}
