<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

use Selli\Ticketing\Contracts\AiSettingsResolver;
use Selli\Ticketing\Enums\AiMode;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\WidgetKey;

/**
 * Config-backed AI settings. The mode is resolved most-specific first:
 *
 * 1. `ai.modes.tenants.{tenant}.types.{type}` (when the tenant entry is an array),
 * 2. `ai.modes.tenants.{tenant}` (a mode string) or its `mode` key,
 * 3. `ai.modes.types.{type}`,
 * 4. `ai.mode`.
 *
 * Everything is off while `ai.enabled` is false. The audience is the one of the
 * widget key the conversation came through (its customer scope), else an
 * identified customer with no scope.
 */
class ConfigAiSettings implements AiSettingsResolver
{
    public function mode(int|string|null $tenant, ?string $ticketType, ?string $channel): AiMode
    {
        if (config('ticketing.ai.enabled', false) !== true) {
            return AiMode::Off;
        }

        $tenantEntry = $tenant !== null ? config('ticketing.ai.modes.tenants.'.$tenant) : null;

        if (is_array($tenantEntry)) {
            if ($ticketType !== null && is_string($tenantEntry['types'][$ticketType] ?? null)) {
                return $this->parse($tenantEntry['types'][$ticketType]);
            }

            if (is_string($tenantEntry['mode'] ?? null)) {
                return $this->parse($tenantEntry['mode']);
            }
        } elseif (is_string($tenantEntry)) {
            return $this->parse($tenantEntry);
        }

        $typeEntry = $ticketType !== null ? config('ticketing.ai.modes.types.'.$ticketType) : null;

        if (is_string($typeEntry)) {
            return $this->parse($typeEntry);
        }

        return $this->parse(config('ticketing.ai.mode', 'off'));
    }

    public function audience(Ticket|Conversation $subject): KnowledgeAudience
    {
        $conversation = $subject instanceof Conversation
            ? $subject
            : $subject->conversation()->withoutTenancy()->first();

        if ($conversation === null) {
            // Email / in-app tickets: an identified requester, no audience scope.
            return KnowledgeAudience::customer();
        }

        $key = $conversation->widgetKey()->withoutTenancy()->first();
        $identified = $conversation->identity_verified || $conversation->requester_type !== null;

        // An anonymous visitor only ever gets public articles; a verified one
        // also customer articles — within the widget's customer scope, if any.
        return $key instanceof WidgetKey
            ? KnowledgeAudience::scoped($key->audience_type, $key->audience_id, $identified)
            : KnowledgeAudience::scoped(null, null, $identified);
    }

    protected function parse(mixed $mode): AiMode
    {
        // An unknown value fails closed to off rather than letting the AI loose.
        return is_string($mode) ? (AiMode::tryFrom($mode) ?? AiMode::Off) : AiMode::Off;
    }
}
