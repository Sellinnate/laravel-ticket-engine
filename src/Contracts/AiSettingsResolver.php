<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Selli\Ticketing\Enums\AiMode;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\Ticket;

/**
 * Decides how much the AI may do and for whom it answers. The default reads
 * `ticketing.ai.*`; bind your own to drive it from a settings table per tenant.
 */
interface AiSettingsResolver
{
    /**
     * The AI mode for a tenant, ticket type and channel.
     */
    public function mode(int|string|null $tenant, ?string $ticketType, ?string $channel): AiMode;

    /**
     * The knowledge-base audience the AI answers for (it bounds the sources
     * the AI may draw on: never an internal runbook for a customer).
     */
    public function audience(Ticket|Conversation $subject): KnowledgeAudience;
}
