<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Selli\Ticketing\Actions\EscalateTicket;
use Selli\Ticketing\Actions\PostMessage;
use Selli\Ticketing\Contracts\AiSettingsResolver;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Conversations\ConversationService;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Enums\AiMode;
use Selli\Ticketing\Enums\AiOutcome;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\ConversationRole;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Events\AiDraftCreated;
use Selli\Ticketing\Events\AiReplied;
use Selli\Ticketing\Events\AiTurnFailed;
use Selli\Ticketing\Events\AiUsageRecorded;
use Selli\Ticketing\Models\AiTurn;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\ConversationMessage;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\Ticketing;
use Throwable;

/**
 * Runs one AI turn for a customer message, on a ticket or in a widget
 * conversation:
 *
 * 1. resolve the mode (auto / draft / off) for the tenant, type and channel;
 * 2. claim the turn — one per triggering message, so a retried job never
 *    answers twice;
 * 3. classify (first turn), check the escalation rules, ask the driver for an
 *    answer, check the rules again on the answer;
 * 4. under the ticket/conversation row lock, re-check that no human took over
 *    in the meantime, then reply (auto), write a draft (draft) or escalate;
 * 5. record outcome, confidence and usage on the turn and emit the events.
 *
 * The slow driver calls happen outside any lock or transaction.
 *
 * @internal
 */
class AiAssistant
{
    public function __construct(
        protected AiManager $ai,
        protected EscalationPolicy $policy,
        protected AiRequestFactory $requests,
        protected Container $container,
    ) {}

    /**
     * @param  bool  $retry  a retry of a job that may have died mid-turn: an
     *                       unfinished turn for this message is taken back
     */
    public function respondToTicket(Ticket $ticket, TicketMessage $trigger, bool $retry = false): ?AiTurn
    {
        // Work on the stored state (status, exchanges), not a caller's copy.
        $ticket = Ticketing::ticketModel()::query()->withoutTenancy()->whereKey($ticket->getKey())->first() ?? $ticket;
        $settings = $this->settings();
        $tenant = $ticket->getAttribute($ticket->getTenantColumn());
        $mode = $settings->mode($tenant, $this->requests->typeKey($ticket), $ticket->channel);

        if ($mode === AiMode::Off || ($ticket->ai_status !== null && ! $ticket->ai_status->answers())) {
            return null;
        }

        $turn = $this->claim('ticket_message:'.$trigger->getKey(), $ticket, null, $mode, $trigger->body, $retry);

        if ($turn === null) {
            return null;
        }

        $started = hrtime(true);
        $usage = AiUsage::none();
        $driver = $this->ai->driver();

        try {
            $request = $this->requests->forTicket($ticket, $trigger, $settings->audience($ticket));

            // Every turn is classified — a customer may ask for a person at any
            // point — but the ticket's fields only take the first classification.
            $classification = AiClassification::none();
            if (config('ticketing.ai.classification.enabled', true) !== false) {
                $classification = $driver->classify($request);
                $usage = $usage->plus($classification->usage);

                if ($ticket->ai_status === null) {
                    $this->applyClassification($ticket, $classification);
                }
            }

            $reason = $this->policy->beforeAnswer($classification, $ticket->ai_exchanges);
            $answer = null;

            // In draft mode an answer is still useful to the agent even when a
            // rule says a human must handle it.
            if ($reason === null || $mode === AiMode::Draft) {
                $answer = $driver->answer($request);
                $usage = $usage->plus($answer->usage);
                $reason ??= $this->policy->afterAnswer($answer);
            }
        } catch (Throwable $exception) {
            return $this->failTicket($ticket, $turn, $mode, $exception, $usage, $started);
        }

        return $this->completeTicketTurn($ticket, $trigger, $turn, $mode, $reason, $answer, $usage, $started);
    }

    public function respondToConversation(Conversation $conversation, ConversationMessage $trigger, bool $retry = false): ?AiTurn
    {
        $conversation = Ticketing::conversationModel()::query()->withoutTenancy()->whereKey($conversation->getKey())->first() ?? $conversation;
        $settings = $this->settings();
        $tenant = $conversation->getAttribute($conversation->getTenantColumn());
        $mode = $settings->mode($tenant, $this->conversationType($conversation), $conversation->channel);

        // A hand-over is waiting for the visitor's contact details: the rules
        // already decided a human must answer.
        if ($mode !== AiMode::Auto || ! $conversation->isOpen() || ($conversation->meta['handoff_pending'] ?? false) === true) {
            return null;
        }

        $turn = $this->claim('conversation_message:'.$trigger->getKey(), null, $conversation, $mode, $trigger->body, $retry);

        if ($turn === null) {
            return null;
        }

        $started = hrtime(true);
        $usage = AiUsage::none();
        $driver = $this->ai->driver();

        try {
            $request = $this->requests->forConversation($conversation, $trigger, $settings->audience($conversation));

            $classification = AiClassification::none();
            if (config('ticketing.ai.classification.enabled', true) !== false) {
                $classification = $driver->classify($request);
                $usage = $usage->plus($classification->usage);

                if ($conversation->locale === null && $classification->language !== null) {
                    $conversation->forceFill(['locale' => mb_substr($classification->language, 0, 12)])->save();
                }
            }

            $reason = $this->policy->beforeAnswer($classification, $conversation->ai_exchanges);
            $answer = null;

            if ($reason === null) {
                $answer = $driver->answer($request);
                $usage = $usage->plus($answer->usage);
                $reason = $this->policy->afterAnswer($answer);
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->finish($turn, AiOutcome::Failed, EscalationReason::AiError, null, $usage, $started, $exception->getMessage());
            AiTurnFailed::dispatch($turn, $exception->getMessage());
            $this->conversations()->escalate($conversation, EscalationReason::AiError);

            return $turn;
        }

        // No answer means escalate, even when no rule named a reason.
        $escalation = $reason ?? ($answer === null ? EscalationReason::NoSources : null);

        $reply = DB::transaction(function () use ($conversation, $turn, $escalation, $answer, $usage, $started): ?ConversationMessage {
            /** @var Conversation $locked */
            $locked = Ticketing::conversationModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->whereKey($conversation->getKey())
                ->firstOrFail();

            if (! $locked->isOpen()) {
                $this->finish($turn, AiOutcome::Skipped, null, $answer, $usage, $started);

                return null;
            }

            if ($escalation !== null || $answer === null) {
                $this->finish($turn, AiOutcome::Escalated, $escalation, $answer, $usage, $started);

                return null;
            }

            /** @var ConversationMessage $message */
            $message = Ticketing::conversationMessageModel()::query()->create(array_merge($locked->tenantAttributes(), [
                'conversation_id' => $locked->getKey(),
                'role' => ConversationRole::Assistant,
                'body' => $answer->body,
                'sources' => $answer->sourcesArray(),
                'confidence' => $answer->confidence,
                'meta' => ['ai_turn_id' => $turn->getKey()],
            ]));

            $locked->forceFill([
                'ai_exchanges' => $locked->ai_exchanges + 1,
                'message_count' => $locked->message_count + 1,
                'last_activity_at' => now(),
            ])->save();

            $this->finish($turn, AiOutcome::Answered, null, $answer, $usage, $started);

            return $message;
        });

        AiUsageRecorded::dispatch($turn);

        if ($reply !== null) {
            AiReplied::dispatch($turn, $reply);
        } elseif ($turn->outcome === AiOutcome::Escalated && $turn->escalation_reason !== null) {
            $this->conversations()->escalate($conversation->refresh(), $turn->escalation_reason);
        }

        return $turn;
    }

    protected function completeTicketTurn(
        Ticket $ticket,
        TicketMessage $trigger,
        AiTurn $turn,
        AiMode $mode,
        ?EscalationReason $reason,
        ?AiAnswer $answer,
        AiUsage $usage,
        int $started,
    ): AiTurn {
        $written = DB::transaction(function () use ($ticket, $trigger, $turn, $mode, $reason, $answer, $usage, $started): ?TicketMessage {
            /** @var Ticket $locked */
            $locked = Ticketing::ticketModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->whereKey($ticket->getKey())
                ->firstOrFail();

            // A human took over (or the ticket was escalated) while the driver
            // was thinking: never write after a takeover.
            if (($locked->ai_status !== null && ! $locked->ai_status->answers()) || $this->agentRepliedSince($locked, $trigger)) {
                $this->finish($turn, AiOutcome::Skipped, null, $answer, $usage, $started);

                return null;
            }

            if ($locked->ai_status === null) {
                $locked->forceFill(['ai_status' => AiStatus::Active])->save();
            }

            if ($mode === AiMode::Draft) {
                $draft = null;

                if ($answer !== null && ! $answer->isEmpty()) {
                    $draft = $this->post($locked, $answer, MessageVisibility::Internal, $turn, 'draft');
                }

                $this->finish($turn, $reason !== null ? AiOutcome::Escalated : AiOutcome::Drafted, $reason, $answer, $usage, $started);

                return $draft;
            }

            if ($reason !== null || $answer === null) {
                $this->finish($turn, AiOutcome::Escalated, $reason ?? EscalationReason::NoSources, $answer, $usage, $started);

                return null;
            }

            $reply = $this->post($locked, $answer, MessageVisibility::Public, $turn, 'reply');

            $locked->forceFill([
                'ai_exchanges' => $locked->ai_exchanges + 1,
                'first_ai_response_at' => $locked->first_ai_response_at ?? now(),
            ])->save();

            $this->finish($turn, AiOutcome::Answered, null, $answer, $usage, $started);

            return $reply;
        });

        AiUsageRecorded::dispatch($turn);

        if ($written !== null && $mode === AiMode::Draft) {
            AiDraftCreated::dispatch($ticket->refresh(), $written, $turn);
        } elseif ($written !== null) {
            AiReplied::dispatch($turn, $written);
        }

        if ($turn->outcome === AiOutcome::Escalated && $turn->escalation_reason !== null) {
            $this->container->make(EscalateTicket::class)->handle($ticket->refresh(), $turn->escalation_reason, notifyCustomer: $mode === AiMode::Auto);
        }

        return $turn;
    }

    protected function failTicket(Ticket $ticket, AiTurn $turn, AiMode $mode, Throwable $exception, AiUsage $usage, int $started): AiTurn
    {
        report($exception);

        $this->finish($turn, AiOutcome::Failed, EscalationReason::AiError, null, $usage, $started, $exception->getMessage());

        AiUsageRecorded::dispatch($turn);
        AiTurnFailed::dispatch($turn, $exception->getMessage());

        // Always on: a provider error hands the customer to a human.
        $this->container->make(EscalateTicket::class)->handle($ticket->refresh(), EscalationReason::AiError, notifyCustomer: $mode === AiMode::Auto);

        return $turn;
    }

    /**
     * Claim the turn for a triggering message: the unique trigger key makes a
     * duplicate delivery a no-op. A turn left "running" by a worker that died
     * (nothing of it was committed: the writes and the completion share one
     * transaction) is taken back by a retry of the job, or by anyone once it
     * is older than `ai.turn_timeout` seconds.
     */
    protected function claim(string $triggerKey, ?Ticket $ticket, ?Conversation $conversation, AiMode $mode, string $question, bool $retry = false): ?AiTurn
    {
        $subject = $ticket ?? $conversation;
        $model = Ticketing::aiTurnModel();
        $plain = AiRequestFactory::plain($question);

        /** @var AiTurn $turn */
        $turn = $model::query()->withoutTenancy()->createOrFirst(
            ['trigger_key' => $triggerKey],
            array_merge($subject !== null ? $subject->tenantAttributes() : [], [
                'ticket_id' => $ticket?->getKey(),
                'conversation_id' => $conversation?->getKey(),
                'mode' => $mode,
                'status' => 'running',
                'driver' => $this->ai->defaultDriver(),
                'question' => mb_substr($plain, 0, 500),
                'question_fingerprint' => self::fingerprint($plain),
            ]),
        );

        if ($turn->wasRecentlyCreated) {
            return $turn;
        }

        if ($turn->status !== 'running') {
            return null;
        }

        $timeout = max(30, (int) config('ticketing.ai.turn_timeout', 300));

        $reclaimed = $model::query()->withoutTenancy()
            ->whereKey($turn->getKey())
            ->where('status', 'running')
            ->when(! $retry, fn ($query) => $query->where('updated_at', '<', now()->subSeconds($timeout)))
            ->update(['updated_at' => now(), 'mode' => $mode->value, 'attempts' => DB::raw('attempts + 1')]);

        return $reclaimed === 1 ? $turn->refresh() : null;
    }

    protected function finish(AiTurn $turn, AiOutcome $outcome, ?EscalationReason $reason, ?AiAnswer $answer, AiUsage $usage, int $started, ?string $error = null): void
    {
        $turn->forceFill([
            'status' => 'done',
            'outcome' => $outcome,
            'escalation_reason' => $reason,
            'confidence' => $answer?->confidence,
            'sources_count' => $answer !== null ? count($answer->sources) : 0,
            'model' => $usage->model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost' => $usage->cost,
            'currency' => $usage->currency,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'error' => $error !== null ? mb_substr($error, 0, 255) : null,
        ])->save();
    }

    protected function post(Ticket $ticket, AiAnswer $answer, MessageVisibility $visibility, AiTurn $turn, string $kind): TicketMessage
    {
        return $this->container->make(PostMessage::class)->handle(new PostMessageData(
            ticket: $ticket,
            author: null,
            body: $answer->body,
            visibility: $visibility,
            source: MessageSource::Ai,
            meta: ['ai' => [
                'kind' => $kind,
                'turn_id' => $turn->getKey(),
                'confidence' => round($answer->confidence, 4),
                'sources' => $answer->sourcesArray(),
                'model' => $answer->usage->model,
            ]],
        ));
    }

    /**
     * Language, sentiment and — when allowed — category and a higher priority
     * from the classifier, on the ticket's first AI turn.
     */
    protected function applyClassification(Ticket $ticket, AiClassification $classification): void
    {
        $changes = array_filter([
            'language' => $classification->language !== null ? mb_substr($classification->language, 0, 12) : null,
            'sentiment' => $classification->sentiment,
        ], fn (mixed $value): bool => $value !== null);

        if ($classification->category !== null && $ticket->category === null
            && config('ticketing.ai.classification.apply_category', true) !== false) {
            $changes['category'] = mb_substr($classification->category, 0, 255);
        }

        if ($changes !== []) {
            $ticket->forceFill($changes)->save();
        }

        if ($classification->priority instanceof Priority
            && config('ticketing.ai.classification.apply_priority', true) !== false
            && $classification->priority->value > $ticket->priority->value) {
            app(Ticketing::class)->changePriority($ticket, $classification->priority);
        }
    }

    /**
     * Whether an agent replied publicly after the triggering message.
     */
    protected function agentRepliedSince(Ticket $ticket, TicketMessage $trigger): bool
    {
        $model = Ticketing::ticketMessageModel();

        $later = $model::query()->withoutTenancy()
            ->with('author')
            ->where('ticket_id', $ticket->getKey())
            ->where('visibility', MessageVisibility::Public->value)
            ->whereNotNull('author_id')
            ->where((new $model)->getKeyName(), '>', $trigger->getKey())
            ->get();

        foreach ($later as $message) {
            if ($message->author instanceof CanActOnTickets && ! $this->requests->isRequester($ticket, $message->author)) {
                return true;
            }
        }

        return false;
    }

    protected function conversationType(Conversation $conversation): ?string
    {
        $type = $conversation->widgetKey()->withoutTenancy()->value('ticket_type');

        return is_string($type) && $type !== '' ? $type : null;
    }

    protected function settings(): AiSettingsResolver
    {
        return $this->container->make(AiSettingsResolver::class);
    }

    protected function conversations(): ConversationService
    {
        return $this->container->make(ConversationService::class);
    }

    /**
     * Groups repeated questions for the knowledge-gap report: lower-cased,
     * punctuation and extra spaces removed, first 200 characters.
     */
    public static function fingerprint(string $question): string
    {
        $normalised = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($question))));

        return hash('sha256', Str::limit($normalised, 200, ''));
    }
}
