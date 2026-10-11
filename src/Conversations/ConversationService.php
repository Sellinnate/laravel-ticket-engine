<?php

declare(strict_types=1);

namespace Selli\Ticketing\Conversations;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Selli\Ticketing\Actions\EscalateTicket;
use Selli\Ticketing\Actions\PostMessage;
use Selli\Ticketing\Actions\TakeOverTicket;
use Selli\Ticketing\Contacts\ContactDirectory;
use Selli\Ticketing\Contracts\AiSettingsResolver;
use Selli\Ticketing\Contracts\VisitorResolver;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Enums\AiMode;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\ConversationRole;
use Selli\Ticketing\Enums\ConversationStatus;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Events\ConversationClosed;
use Selli\Ticketing\Events\ConversationEscalated;
use Selli\Ticketing\Events\ConversationStarted;
use Selli\Ticketing\Exceptions\ConversationException;
use Selli\Ticketing\Jobs\RunAiTurn;
use Selli\Ticketing\Models\Contact;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\ConversationMessage;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Models\WidgetKey;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * The conversational channel behind chat widgets: start a conversation, post
 * messages (the AI answers while it is open), hand it to humans — it becomes a
 * ticket carrying the transcript — and close it. `Ticketing::conversations()`
 * returns it; the widget JSON API is a thin layer on top.
 */
class ConversationService
{
    public function __construct(
        protected TenantContext $tenant,
        protected ContactDirectory $contacts,
        protected WidgetKeys $keys,
        protected Container $container,
    ) {}

    /**
     * Start a conversation through a widget key (in the key's tenant). A signed
     * identity links it to the host user (VisitorResolver) or a verified
     * contact; an unsigned one is only remembered as a claim.
     *
     * @param  array{locale?: string|null, origin?: string|null, ip?: string|null, channel?: string|null}  $options
     * @return array{conversation: Conversation, token: string}
     */
    public function start(WidgetKey $key, ?VisitorIdentity $identity = null, array $options = []): array
    {
        return $this->tenant->forTenant($key->getAttribute($key->getTenantColumn()), function () use ($key, $identity, $options): array {
            $token = Str::random(48);
            $verified = $identity !== null && $this->keys->verifyIdentity($key, $identity);

            $requester = null;
            $contact = null;

            if ($verified && $identity !== null) {
                $requester = $this->container->make(VisitorResolver::class)->resolve($identity, $key);

                // The email is only authenticated when the signature covers it
                // (no user_id). A signed user_id says nothing about an email
                // sent next to it: that email stays a claim.
                if ($requester === null && $identity->userId === null && ContactDirectory::normaliseEmail($identity->email) !== null) {
                    $contact = $this->contacts->markVerified($this->contacts->remember((string) $identity->email, $identity->name));
                }
            }

            $model = Ticketing::conversationModel();

            /** @var Conversation $conversation */
            $conversation = $model::query()->create(array_filter([
                'public_id' => strtolower((string) Str::ulid()),
                'widget_key_id' => $key->getKey(),
                'channel' => $options['channel'] ?? $key->channel,
                'contact_id' => $contact?->getKey(),
                'requester_type' => $requester?->getMorphClass(),
                'requester_id' => $requester?->getKey(),
                'identity_verified' => $verified,
                'token_hash' => hash('sha256', $token),
                'status' => ConversationStatus::Open,
                'locale' => isset($options['locale']) ? mb_substr((string) $options['locale'], 0, 12) : $key->setting('locale'),
                'origin' => isset($options['origin']) ? mb_substr((string) $options['origin'], 0, 255) : null,
                'ip_hash' => isset($options['ip']) ? hash('sha256', (string) $options['ip'].'|'.config('app.key')) : null,
                'last_activity_at' => now(),
                // Unauthenticated details are only a claim: kept for the
                // escalation form, never trusted as a verified requester.
                'meta' => $contact === null && $requester === null && $identity !== null && ($identity->email !== null || $identity->name !== null)
                    ? ['claimed' => array_filter(['email' => $identity->email, 'name' => $identity->name])]
                    : null,
            ], fn (mixed $value): bool => $value !== null));

            ConversationStarted::dispatch($conversation);

            return ['conversation' => $conversation, 'token' => $token];
        });
    }

    /**
     * The conversation a public id and bearer token name, or null (unknown id,
     * wrong token, expired token). Constant-time token check.
     */
    public function authenticate(string $publicId, string $token, ?WidgetKey $key = null): ?Conversation
    {
        $conversation = Ticketing::conversationModel()::query()->withoutTenancy()
            ->where('public_id', strtolower($publicId))
            ->first();

        if (! $conversation instanceof Conversation || ! $conversation->matchesToken($token)) {
            return null;
        }

        if ($key !== null && (string) $conversation->widget_key_id !== (string) $key->getKey()) {
            return null;
        }

        $ttl = (int) config('ticketing.widget.token_ttl', 604800);

        if ($ttl > 0 && $conversation->created_at !== null && $conversation->created_at->copy()->addSeconds($ttl)->isPast()) {
            return null;
        }

        return $conversation;
    }

    /**
     * A visitor message. While the conversation is open it is stored on the
     * conversation and handed to the AI (or, with AI off for this widget, the
     * conversation is escalated straight away); once escalated it is appended
     * to the ticket as the requester's reply.
     *
     * @throws ConversationException when the conversation is closed or full
     */
    public function post(Conversation $conversation, string $body): ConversationMessage|TicketMessage
    {
        return $this->inTenant($conversation, function () use ($conversation, $body): ConversationMessage|TicketMessage {
            $body = trim($body);

            if ($body === '') {
                throw ConversationException::emptyMessage();
            }

            /** @var array{0: Conversation, 1: ConversationMessage|null} $result */
            $result = DB::transaction(function () use ($conversation, $body): array {
                $locked = $this->lock($conversation);

                if ($locked->status === ConversationStatus::Closed) {
                    throw ConversationException::closed();
                }

                $max = (int) config('ticketing.widget.max_messages_per_conversation', 50);

                // The cap guards the AI phase; once a person handles the ticket
                // the visitor may keep answering (rate limits still apply).
                if ($locked->status === ConversationStatus::Open && $max > 0 && $locked->message_count >= $max) {
                    throw ConversationException::tooManyMessages();
                }

                $locked->forceFill([
                    'message_count' => $locked->message_count + 1,
                    'last_activity_at' => now(),
                ])->save();

                if ($locked->status === ConversationStatus::Escalated) {
                    return [$locked, null];
                }

                /** @var ConversationMessage $message */
                $message = Ticketing::conversationMessageModel()::query()->create(array_merge($locked->tenantAttributes(), [
                    'conversation_id' => $locked->getKey(),
                    'role' => ConversationRole::Visitor,
                    'body' => $body,
                ]));

                return [$locked, $message];
            });

            [$locked, $message] = $result;

            if ($message === null) {
                return $this->appendToTicket($locked, $body);
            }

            if ($this->mode($locked) === AiMode::Auto) {
                RunAiTurn::dispatchFor($locked, $message);
            } else {
                // No AI for this widget: humans answer, so the conversation is a
                // ticket from the first message.
                $this->escalate($locked);
            }

            return $message;
        });
    }

    /**
     * Hand the conversation to humans: it becomes a ticket (in the widget's
     * ticket type, on the conversation's channel) carrying the transcript.
     * Anonymous visitors must leave an email first when
     * `widget.require_email_to_escalate` is on: without one an AI-triggered
     * hand-over is parked (meta.handoff_pending) and a system message asks for
     * contact details. Escalating twice returns the same ticket.
     */
    public function escalate(Conversation $conversation, ?EscalationReason $reason = null, ?string $name = null, ?string $email = null): ?Ticket
    {
        return $this->inTenant($conversation, function () use ($conversation, $reason, $name, $email): ?Ticket {
            $outcome = DB::transaction(function () use ($conversation, $reason, $name, $email): array {
                $locked = $this->lock($conversation);

                if ($locked->status === ConversationStatus::Escalated && $locked->ticket_id !== null) {
                    $existing = Ticketing::ticketModel()::query()->withoutTenancy()->find($locked->ticket_id);

                    // A hand-over interrupted after the ticket committed (a
                    // crash, a failing listener) is finished by the next call.
                    $pending = ($locked->meta['handover'] ?? null) === 'pending';

                    return [$locked, $existing instanceof Ticket ? $existing : null, $pending];
                }

                if ($locked->status === ConversationStatus::Closed) {
                    throw ConversationException::closed();
                }

                $requester = $this->requester($locked, $name, $email);

                if ($requester === null && config('ticketing.widget.require_email_to_escalate', true) !== false) {
                    $this->parkHandoff($locked, $reason);

                    return [$locked, null, false];
                }

                $ticket = $this->openTicket($locked, $requester);

                $locked->forceFill([
                    'status' => ConversationStatus::Escalated,
                    'ticket_id' => $ticket->getKey(),
                    'escalated_at' => now(),
                    'escalation_reason' => $reason,
                    'contact_id' => $requester instanceof Contact ? $requester->getKey() : $locked->contact_id,
                    // Cleared once the side effects below have all run.
                    'meta' => array_merge(array_diff_key($locked->meta ?? [], ['handoff_pending' => true]), ['handover' => 'pending']),
                ])->save();

                $this->container->make(AuditLogger::class)->record(
                    ticket: $ticket,
                    event: 'conversation.escalated',
                    context: array_filter(['conversation' => $locked->public_id, 'reason' => $reason?->value]),
                );

                return [$locked, $ticket, true];
            });

            /** @var array{0: Conversation, 1: Ticket|null, 2: bool} $outcome */
            [$locked, $ticket, $handOver] = $outcome;

            if ($ticket === null || ! $handOver) {
                return $ticket;
            }

            $mode = $this->mode($locked);

            // Draft mode: the AI never answered the visitor, but it drafts a reply
            // to their latest message for the agent who picks the ticket up —
            // also when the visitor first had to leave their contact details.
            if ($mode === AiMode::Draft) {
                $this->draftOnTranscript($ticket);
            }

            // An answering AI (auto mode) is handed over formally — routing
            // fallback, team leads' notification, agent summary — and stops on
            // the ticket, even before its first answer. Otherwise the ticket was
            // routed on open like any other.
            // Every step is idempotent, so a resumed hand-over repeats none.
            if ($mode === AiMode::Auto) {
                $ticket = $this->container->make(EscalateTicket::class)->handle(
                    $ticket,
                    $locked->escalation_reason ?? $reason ?? EscalationReason::HumanRequested,
                    notifyCustomer: true,
                    resume: true,
                );
            }

            $this->announceEscalation($locked, $ticket);

            return $ticket;
        });
    }

    /**
     * Close the hand-over: the marker is cleared in the transaction that
     * dispatches the (after-commit) event, so it is announced exactly once.
     */
    protected function announceEscalation(Conversation $conversation, Ticket $ticket): void
    {
        DB::transaction(function () use ($conversation, $ticket): void {
            $locked = $this->lock($conversation);

            if (($locked->meta['handover'] ?? null) !== 'pending') {
                return;
            }

            $locked->forceFill(['meta' => array_diff_key($locked->meta ?? [], ['handover' => true]) ?: null])->save();

            ConversationEscalated::dispatch($locked, $ticket);
        });
    }

    /**
     * An agent takes the conversation over at any moment: it becomes a ticket
     * (if it is not one already) owned by that agent, and the AI stops.
     */
    public function takeOver(Conversation $conversation, Model $agent, ?string $name = null, ?string $email = null): ?Ticket
    {
        $ticket = $this->escalate($conversation, EscalationReason::Manual, $name, $email);

        if ($ticket === null) {
            return null;
        }

        return $this->container->make(TakeOverTicket::class)->handle($ticket, $agent);
    }

    /**
     * End an open conversation. One the AI answered without a hand-over counts
     * as resolved by the AI (resolved_by_ai) in the KPIs.
     */
    public function close(Conversation $conversation): Conversation
    {
        $closed = DB::transaction(function () use ($conversation): ?Conversation {
            $locked = $this->lock($conversation);

            if ($locked->status !== ConversationStatus::Open) {
                return null;
            }

            $locked->forceFill([
                'status' => ConversationStatus::Closed,
                'closed_at' => now(),
                'resolved_by_ai' => $locked->ai_exchanges > 0,
            ])->save();

            return $locked;
        });

        if ($closed === null) {
            return $conversation->refresh();
        }

        ConversationClosed::dispatch($closed);

        return $closed;
    }

    /**
     * What the visitor sees, oldest first: the conversation before the
     * hand-over, then the ticket's public messages after it (never internal
     * notes, never the transcript copy). With $since, only messages at or after
     * that instant (timestamps have second precision: clients de-duplicate).
     *
     * @return list<array{id: string, role: string, body: string, sources: list<array<string, mixed>>, at: string|null}>
     */
    public function timeline(Conversation $conversation, ?Carbon $since = null): array
    {
        $items = [];

        // Timestamps are stored in the application timezone and bound without
        // an offset: a client's "since" in another offset is converted first.
        $since = $since?->copy()->setTimezone((string) config('app.timezone', 'UTC'));

        $messages = Ticketing::conversationMessageModel()::query()->withoutTenancy()
            ->where('conversation_id', $conversation->getKey())
            ->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since))
            ->orderBy('created_at')
            ->orderBy((new (Ticketing::conversationMessageModel()))->getKeyName())
            ->get();

        foreach ($messages as $message) {
            $items[] = [
                'id' => self::opaqueId('conversation_message', $message->getKey()),
                'role' => $message->role->value,
                'body' => $message->body,
                'sources' => $this->publicSources($message->sources ?? []),
                'at' => $message->created_at?->toIso8601String(),
            ];
        }

        if ($conversation->ticket_id !== null && $conversation->escalated_at !== null) {
            $after = $since !== null && $since->greaterThan($conversation->escalated_at) ? $since : $conversation->escalated_at;

            $ticketMessages = Ticketing::ticketMessageModel()::query()->withoutTenancy()
                ->where('ticket_id', $conversation->ticket_id)
                ->where('visibility', MessageVisibility::Public->value)
                ->where('created_at', '>=', $after)
                ->orderBy('created_at')
                ->orderBy((new (Ticketing::ticketMessageModel()))->getKeyName())
                ->get();

            foreach ($ticketMessages as $message) {
                if (($message->meta['transcript'] ?? false) === true) {
                    continue;
                }

                $items[] = [
                    'id' => self::opaqueId('ticket_message', $message->getKey()),
                    'role' => $this->ticketRole($conversation, $message),
                    'body' => $message->body,
                    'sources' => $this->publicSources((array) data_get($message->meta, 'ai.sources', [])),
                    'at' => $message->created_at?->toIso8601String(),
                ];
            }
        }

        return $items;
    }

    /**
     * A stable, opaque message id for the browser: unique per message, but
     * neither the internal key nor reversible to it.
     */
    protected static function opaqueId(string $kind, int|string $key): string
    {
        return substr(hash_hmac('sha256', $kind.':'.$key, (string) config('app.key')), 0, 20);
    }

    protected function appendToTicket(Conversation $conversation, string $body): TicketMessage
    {
        $ticket = Ticketing::ticketModel()::query()->withoutTenancy()->find($conversation->ticket_id);

        if (! $ticket instanceof Ticket) {
            throw ConversationException::closed();
        }

        return $this->container->make(PostMessage::class)->handle(new PostMessageData(
            ticket: $ticket,
            author: $this->existingRequester($conversation),
            body: $body,
            visibility: MessageVisibility::Public,
            source: MessageSource::Widget,
            meta: ['conversation' => $conversation->public_id],
        ));
    }

    /**
     * The ticket requester: the verified host user, the conversation's contact,
     * or a contact from the details the visitor typed in the hand-over form.
     */
    protected function requester(Conversation $conversation, ?string $name, ?string $email): ?Model
    {
        $existing = $this->existingRequester($conversation);

        if ($existing !== null) {
            return $existing;
        }

        $claimed = is_array($conversation->meta['claimed'] ?? null) ? $conversation->meta['claimed'] : [];
        $email ??= is_string($claimed['email'] ?? null) ? $claimed['email'] : null;
        $name ??= is_string($claimed['name'] ?? null) ? $claimed['name'] : null;

        if (ContactDirectory::normaliseEmail($email) === null) {
            return null;
        }

        return $this->contacts->remember((string) $email, $name);
    }

    protected function existingRequester(Conversation $conversation): ?Model
    {
        if ($conversation->requester_type !== null && $conversation->requester_id !== null) {
            $requester = $conversation->requester;

            if ($requester instanceof Model) {
                return $requester;
            }
        }

        if ($conversation->contact_id !== null) {
            $contact = Ticketing::contactModel()::query()->withoutTenancy()->find($conversation->contact_id);

            return $contact instanceof Contact ? $contact : null;
        }

        return null;
    }

    protected function openTicket(Conversation $conversation, ?Model $requester): Ticket
    {
        $key = $conversation->widgetKey()->withoutTenancy()->first();
        $type = $key instanceof WidgetKey ? $key->effectiveTicketType() : WidgetKey::ticketTypeFor(null);

        $first = Ticketing::conversationMessageModel()::query()->withoutTenancy()
            ->where('conversation_id', $conversation->getKey())
            ->where('role', ConversationRole::Visitor->value)
            ->orderBy('created_at')
            ->value('body');

        $title = is_string($first) && trim($first) !== '' ? Str::limit(trim($first), 120) : 'Conversation '.$conversation->public_id;

        $ticket = app(Ticketing::class)->open(
            type: $type,
            title: $title,
            requester: $requester,
            channel: $conversation->channel,
            attributes: ['custom_fields' => ['conversation' => $conversation->public_id]],
        );

        // The AI handled the conversation: the ticket starts escalated, so the
        // AI never picks up the customer's next message on the ticket.
        if ($conversation->ai_exchanges > 0) {
            $ticket->forceFill(['ai_status' => AiStatus::Active, 'ai_exchanges' => $conversation->ai_exchanges])->save();
        }

        $this->copyTranscript($conversation, $ticket, $requester);

        return $ticket;
    }

    /**
     * Copy the conversation into the ticket without emitting MessagePosted —
     * the customer already saw these messages, so no notification or AI turn
     * must fire for them.
     */
    protected function copyTranscript(Conversation $conversation, Ticket $ticket, ?Model $requester): void
    {
        $messages = Ticketing::conversationMessageModel()::query()->withoutTenancy()
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('role', [ConversationRole::Visitor->value, ConversationRole::Assistant->value])
            ->orderBy('created_at')
            ->orderBy((new (Ticketing::conversationMessageModel()))->getKeyName())
            ->get();

        $model = Ticketing::ticketMessageModel();

        foreach ($messages as $message) {
            $visitor = $message->role === ConversationRole::Visitor;

            $model::query()->create(array_merge($ticket->tenantAttributes(), [
                'ticket_id' => $ticket->getKey(),
                'author_type' => $visitor ? $requester?->getMorphClass() : null,
                'author_id' => $visitor ? $requester?->getKey() : null,
                'visibility' => MessageVisibility::Public,
                'body' => $message->body,
                'source' => $visitor ? MessageSource::Widget : MessageSource::Ai,
                'meta' => array_filter([
                    'transcript' => true,
                    'conversation' => $conversation->public_id,
                    'ai' => $visitor ? null : ['kind' => 'reply', 'sources' => $message->sources ?? [], 'confidence' => $message->confidence],
                ], fn (mixed $value): bool => $value !== null),
                'created_at' => $message->created_at,
                'updated_at' => $message->created_at,
            ]));
        }
    }

    protected function draftOnTranscript(Ticket $ticket): void
    {
        $model = Ticketing::ticketMessageModel();

        $last = $model::query()->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->where('source', MessageSource::Widget->value)
            ->orderByDesc('created_at')
            ->orderByDesc((new $model)->getKeyName())
            ->first();

        if ($last instanceof TicketMessage) {
            RunAiTurn::dispatchFor($ticket, $last);
        }
    }

    protected function parkHandoff(Conversation $conversation, ?EscalationReason $reason): void
    {
        if (($conversation->meta['handoff_pending'] ?? false) === true) {
            return;
        }

        $conversation->forceFill([
            'escalation_reason' => $reason,
            'meta' => array_merge($conversation->meta ?? [], ['handoff_pending' => true]),
        ])->save();

        Ticketing::conversationMessageModel()::query()->create(array_merge($conversation->tenantAttributes(), [
            'conversation_id' => $conversation->getKey(),
            'role' => ConversationRole::System,
            'body' => 'Leave your name and email and a member of our team will follow up.',
            'meta' => ['kind' => 'contact_request'],
        ]));
    }

    protected function ticketRole(Conversation $conversation, TicketMessage $message): string
    {
        if ($message->source === MessageSource::Ai) {
            return ConversationRole::Assistant->value;
        }

        // The visitor's own words, also without a requester (an anonymous
        // hand-over): written from this widget conversation.
        if ($message->source === MessageSource::Widget && ($message->meta['conversation'] ?? null) === $conversation->public_id) {
            return ConversationRole::Visitor->value;
        }

        $requester = $this->existingRequester($conversation);

        if ($requester !== null && $message->author_type === $requester->getMorphClass() && (string) $message->author_id === (string) $requester->getKey()) {
            return ConversationRole::Visitor->value;
        }

        return $message->author_id === null ? ConversationRole::System->value : ConversationRole::Agent->value;
    }

    /**
     * Sources as shown to a visitor: type, title and url only.
     *
     * @param  array<int|string, mixed>  $sources
     * @return list<array<string, mixed>>
     */
    protected function publicSources(array $sources): array
    {
        $out = [];

        foreach ($sources as $source) {
            if (is_array($source) && isset($source['title'])) {
                $out[] = array_filter([
                    'type' => $source['type'] ?? null,
                    'title' => $source['title'],
                    'url' => $source['url'] ?? null,
                ], fn (mixed $value): bool => $value !== null);
            }
        }

        return $out;
    }

    protected function mode(Conversation $conversation): AiMode
    {
        return $this->container->make(AiSettingsResolver::class)->mode(
            $conversation->getAttribute($conversation->getTenantColumn()),
            WidgetKey::ticketTypeFor($conversation->widget_key_id),
            $conversation->channel,
        );
    }

    protected function lock(Conversation $conversation): Conversation
    {
        /** @var Conversation $locked */
        $locked = Ticketing::conversationModel()::query()->withoutTenancy()
            ->lockForUpdate()
            ->whereKey($conversation->getKey())
            ->firstOrFail();

        return $locked;
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function inTenant(Conversation $conversation, \Closure $callback): mixed
    {
        return $this->tenant->forTenant($conversation->getAttribute($conversation->getTenantColumn()), $callback);
    }
}
