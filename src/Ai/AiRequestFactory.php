<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Contracts\WorkflowDriver;
use Selli\Ticketing\Enums\ConversationRole;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\ConversationMessage;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Models\WidgetKey;
use Selli\Ticketing\Support\Ticketing;

/**
 * Builds the {@see AiRequest} for a ticket or a conversation: the latest
 * customer message, the recent public history (internal notes never leave the
 * team) and the context the driver needs.
 *
 * @internal
 */
class AiRequestFactory
{
    /** How many earlier messages the AI sees. */
    protected const HISTORY = 20;

    public function forTicket(Ticket $ticket, ?TicketMessage $trigger, KnowledgeAudience $audience): AiRequest
    {
        $messages = Ticketing::ticketMessageModel()::query()->withoutTenancy()
            ->with('author')
            ->where('ticket_id', $ticket->getKey())
            ->where('visibility', MessageVisibility::Public->value)
            ->when($trigger !== null, fn ($query) => $query->where((new (Ticketing::ticketMessageModel()))->getKeyName(), '<', $trigger?->getKey()))
            ->orderByDesc('created_at')
            ->orderByDesc((new (Ticketing::ticketMessageModel()))->getKeyName())
            ->limit(self::HISTORY)
            ->get()
            ->reverse();

        $requesters = $this->requesterKeys($ticket);

        $history = [];
        foreach ($messages as $message) {
            $history[] = new AiMessage($this->ticketRole($message, $requesters), self::plain($message->body));
        }

        $question = $trigger !== null ? self::plain($trigger->body) : $ticket->title;

        return new AiRequest(
            question: $question,
            audience: $audience,
            history: $history,
            locale: $ticket->language ?? $this->defaultLocale(),
            tenant: $ticket->getAttribute($ticket->getTenantColumn()),
            ticketType: $this->typeKey($ticket),
            channel: $ticket->channel,
            ticket: $ticket,
            context: ['title' => $ticket->title, 'reference' => $ticket->reference],
        );
    }

    public function forConversation(Conversation $conversation, ConversationMessage $trigger, KnowledgeAudience $audience): AiRequest
    {
        $messages = Ticketing::conversationMessageModel()::query()->withoutTenancy()
            ->where('conversation_id', $conversation->getKey())
            ->where((new (Ticketing::conversationMessageModel()))->getKeyName(), '<', $trigger->getKey())
            ->whereIn('role', [ConversationRole::Visitor->value, ConversationRole::Assistant->value, ConversationRole::Agent->value])
            ->orderByDesc('created_at')
            ->orderByDesc((new (Ticketing::conversationMessageModel()))->getKeyName())
            ->limit(self::HISTORY)
            ->get()
            ->reverse();

        $history = [];
        foreach ($messages as $message) {
            $history[] = new AiMessage(match ($message->role) {
                ConversationRole::Visitor => AiMessage::CUSTOMER,
                ConversationRole::Agent => AiMessage::AGENT,
                default => AiMessage::ASSISTANT,
            }, $message->body);
        }

        return new AiRequest(
            question: $trigger->body,
            audience: $audience,
            history: $history,
            locale: $conversation->locale ?? $this->defaultLocale(),
            tenant: $conversation->getAttribute($conversation->getTenantColumn()),
            ticketType: WidgetKey::ticketTypeFor($conversation->widget_key_id),
            channel: $conversation->channel,
            conversation: $conversation,
        );
    }

    /**
     * The ticket type key, for per-type settings.
     */
    public function typeKey(Ticket $ticket): ?string
    {
        $type = Ticketing::ticketTypeModel()::query()->withoutTenancy()->whereKey($ticket->ticket_type_id)->first();

        return $type instanceof TicketType ? $type->key : null;
    }

    /**
     * @param  array<string, true>  $requesters
     */
    protected function ticketRole(TicketMessage $message, array $requesters): string
    {
        if ($message->author_type === null || $message->author_id === null) {
            return $message->source === MessageSource::Ai ? AiMessage::ASSISTANT : AiMessage::CUSTOMER;
        }

        if (isset($requesters[$message->author_type.':'.$message->author_id])) {
            return AiMessage::CUSTOMER;
        }

        return $message->author instanceof CanActOnTickets ? AiMessage::AGENT : AiMessage::CUSTOMER;
    }

    /**
     * @return array<string, true>
     */
    protected function requesterKeys(Ticket $ticket): array
    {
        $keys = [];

        foreach ($ticket->participants()->withoutTenancy()->where('role', ParticipantRole::Requester->value)->get(['participant_type', 'participant_id']) as $participant) {
            $keys[$participant->participant_type.':'.$participant->participant_id] = true;
        }

        return $keys;
    }

    /**
     * Whether the ticket sits in a resolved/closed state of its workflow.
     */
    public function isClosed(Ticket $ticket): bool
    {
        $workflow = Ticketing::ticketTypeModel()::query()->withoutTenancy()->whereKey($ticket->ticket_type_id)->value('workflow');
        $workflow = is_string($workflow) && $workflow !== '' ? $workflow : 'default';

        try {
            return app(WorkflowDriver::class)->matchesSemantic($workflow, $ticket->status, 'closed');
        } catch (InvalidConfigurationException) {
            return false;
        }
    }

    /**
     * Whether a model authored as the ticket's requester.
     */
    public function isRequester(Ticket $ticket, ?Model $author): bool
    {
        if ($author === null) {
            return false;
        }

        return isset($this->requesterKeys($ticket)[$author->getMorphClass().':'.$author->getKey()]);
    }

    protected function defaultLocale(): ?string
    {
        $locale = config('ticketing.knowledge.default_locale') ?? config('app.locale');

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /**
     * Message text without markup, whitespace collapsed.
     */
    public static function plain(string $body): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5)));
    }
}
