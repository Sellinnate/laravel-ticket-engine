<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\Ticket;

/**
 * Everything an AI driver gets for one turn: the customer's latest message, the
 * recent history (oldest first), the locale, and who the answer is for (the
 * knowledge-base audience, which bounds what sources the driver may use).
 */
final readonly class AiRequest
{
    /**
     * @param  list<AiMessage>  $history
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $question,
        public KnowledgeAudience $audience,
        public array $history = [],
        public ?string $locale = null,
        public int|string|null $tenant = null,
        public ?string $ticketType = null,
        public ?string $channel = null,
        public ?Ticket $ticket = null,
        public ?Conversation $conversation = null,
        public array $context = [],
    ) {}
}
