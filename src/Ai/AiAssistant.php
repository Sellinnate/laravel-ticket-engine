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
use Selli\Ticketing\Support\AuditLogger;
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
        $triggerKey = 'ticket_message:'.$trigger->getKey();

        // A hand-over this message caused but a crashed worker never finished.
        $pending = $this->pendingHandoff($triggerKey, $retry);

        if ($pending !== null) {
            return $this->resumeTicketHandoff($ticket, $pending);
        }

        $settings = $this->settings();
        $tenant = $ticket->getAttribute($ticket->getTenantColumn());
        $mode = $settings->mode($tenant, $this->requests->typeKey($ticket), $ticket->channel);

        if ($mode === AiMode::Off || ($ticket->ai_status !== null && ! $ticket->ai_status->answers())) {
            return null;
        }

        $turn = $this->claim($triggerKey, $ticket, null, $mode, $trigger->body, $retry);

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
        $triggerKey = 'conversation_message:'.$trigger->getKey();

        $pending = $this->pendingHandoff($triggerKey, $retry);

        if ($pending !== null) {
            return $this->resumeConversationHandoff($conversation, $pending);
        }

        $settings = $this->settings();
        $tenant = $conversation->getAttribute($conversation->getTenantColumn());
        $mode = $settings->mode($tenant, $this->conversationType($conversation), $conversation->channel);

        // A hand-over is waiting for the visitor's contact details: the rules
        // already decided a human must answer.
        if ($mode !== AiMode::Auto || ! $conversation->isOpen() || ($conversation->meta['handoff_pending'] ?? false) === true) {
            return null;
        }

        $turn = $this->claim($triggerKey, null, $conversation, $mode, $trigger->body, $retry);

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

            $owned = DB::transaction(function () use ($turn, $usage, $started, $exception): bool {
                if (! $this->stillOwns($turn)) {
                    return false;
                }

                $this->finish($turn, AiOutcome::Failed, EscalationReason::AiError, null, $usage, $started, $exception->getMessage(), status: AiTurn::STATUS_HANDOFF);

                return true;
            });

            if (! $owned) {
                return $turn;
            }

            AiUsageRecorded::dispatch($turn);
            AiTurnFailed::dispatch($turn, $exception->getMessage());

            return $this->resumeConversationHandoff($conversation, $turn);
        }

        // No answer means escalate, even when no rule named a reason.
        $escalation = $reason ?? ($answer === null ? EscalationReason::NoSources : null);

        $reply = DB::transaction(function () use ($conversation, $turn, $escalation, $answer, $usage, $started): ?ConversationMessage {
            if (! $this->stillOwns($turn)) {
                return null;
            }

            /** @var Conversation $locked */
            $locked = Ticketing::conversationModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->whereKey($conversation->getKey())
                ->firstOrFail();

            // Closed, escalated, or a concurrent turn parked a hand-over: the AI
            // must not answer any more.
            if (! $locked->isOpen() || ($locked->meta['handoff_pending'] ?? false) === true) {
                $this->finish($turn, AiOutcome::Skipped, null, $answer, $usage, $started);

                return null;
            }

            // A concurrent turn may have used the last allowed answer.
            if ($escalation === null && $this->policy->exchangesExhausted($locked->ai_exchanges)) {
                $escalation = EscalationReason::TooManyExchanges;
            }

            if ($escalation !== null || $answer === null) {
                // Recorded as a pending hand-over: a retry resumes it if this
                // worker dies before the conversation becomes a ticket.
                $this->finish($turn, AiOutcome::Escalated, $escalation, $answer, $usage, $started, status: AiTurn::STATUS_HANDOFF);

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

        if ($turn->status === AiTurn::STATUS_RUNNING) {
            // Another worker owns this turn now: leave everything to it.
            return $turn;
        }

        AiUsageRecorded::dispatch($turn);

        if ($reply !== null) {
            AiReplied::dispatch($turn, $reply);
        } elseif ($turn->status === AiTurn::STATUS_HANDOFF) {
            return $this->resumeConversationHandoff($conversation, $turn);
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
            if (! $this->stillOwns($turn)) {
                return null;
            }

            /** @var Ticket $locked */
            $locked = Ticketing::ticketModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->whereKey($ticket->getKey())
                ->firstOrFail();

            // A human took over, the ticket was escalated or resolved while the
            // driver was thinking: never write after any of those.
            if (($locked->ai_status !== null && ! $locked->ai_status->answers())
                || $this->agentRepliedSince($locked, $trigger)
                || $this->requests->isClosed($locked)) {
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

                if ($reason !== null) {
                    $this->markEscalated($locked, $reason);
                    $this->finish($turn, AiOutcome::Escalated, $reason, $answer, $usage, $started, status: AiTurn::STATUS_HANDOFF);
                } else {
                    $this->finish($turn, AiOutcome::Drafted, null, $answer, $usage, $started);
                }

                return $draft;
            }

            // A concurrent turn may have used the last allowed answer.
            if ($reason === null && $this->policy->exchangesExhausted($locked->ai_exchanges)) {
                $reason = EscalationReason::TooManyExchanges;
            }

            if ($reason !== null || $answer === null) {
                // The escalation commits with the turn; its side effects follow
                // and a retry resumes them if this worker dies first.
                $reason ??= EscalationReason::NoSources;
                $this->markEscalated($locked, $reason);
                $this->finish($turn, AiOutcome::Escalated, $reason, $answer, $usage, $started, status: AiTurn::STATUS_HANDOFF);

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

        if ($turn->status === AiTurn::STATUS_RUNNING) {
            // Another worker owns this turn now: leave everything to it.
            return $turn;
        }

        AiUsageRecorded::dispatch($turn);

        if ($written !== null && $mode === AiMode::Draft) {
            AiDraftCreated::dispatch($ticket->refresh(), $written, $turn);
        } elseif ($written !== null) {
            AiReplied::dispatch($turn, $written);
        }

        if ($turn->status === AiTurn::STATUS_HANDOFF) {
            return $this->resumeTicketHandoff($ticket, $turn);
        }

        return $turn;
    }

    protected function failTicket(Ticket $ticket, AiTurn $turn, AiMode $mode, Throwable $exception, AiUsage $usage, int $started): AiTurn
    {
        report($exception);

        // Always on: a provider error hands the customer to a human — unless,
        // meanwhile, a human took over or the ticket was resolved.
        $handOver = DB::transaction(function () use ($ticket, $turn, $usage, $started, $exception): bool {
            if (! $this->stillOwns($turn)) {
                return false;
            }

            /** @var Ticket $locked */
            $locked = Ticketing::ticketModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->whereKey($ticket->getKey())
                ->firstOrFail();

            if (($locked->ai_status !== null && ! $locked->ai_status->answers()) || $this->requests->isClosed($locked)) {
                $this->finish($turn, AiOutcome::Failed, null, null, $usage, $started, $exception->getMessage());

                return false;
            }

            if ($locked->ai_status === null) {
                $locked->forceFill(['ai_status' => AiStatus::Active])->save();
            }

            $this->markEscalated($locked, EscalationReason::AiError);
            $this->finish($turn, AiOutcome::Failed, EscalationReason::AiError, null, $usage, $started, $exception->getMessage(), status: AiTurn::STATUS_HANDOFF);

            return true;
        });

        AiUsageRecorded::dispatch($turn);
        AiTurnFailed::dispatch($turn, $exception->getMessage());

        return $handOver ? $this->resumeTicketHandoff($ticket, $turn) : $turn;
    }

    /**
     * Whether this worker still owns the turn: re-read under a row lock, the
     * turn must be unfinished and still at the generation (attempts) this
     * worker claimed. A worker whose claim was taken over (it ran past the
     * timeout, or a retry started meanwhile) must not write anything.
     */
    protected function stillOwns(AiTurn $turn): bool
    {
        $current = Ticketing::aiTurnModel()::query()->withoutTenancy()
            ->lockForUpdate()
            ->whereKey($turn->getKey())
            ->first(['status', 'attempts']);

        return $current instanceof AiTurn
            && $current->status === AiTurn::STATUS_RUNNING
            && $current->attempts === $turn->attempts;
    }

    /**
     * The escalation state, committed together with the turn (audited like a
     * manual escalation).
     */
    protected function markEscalated(Ticket $locked, EscalationReason $reason): void
    {
        EscalateTicket::mark($locked, $reason);

        $this->container->make(AuditLogger::class)->record(
            ticket: $locked,
            event: 'ticket.escalated',
            context: ['reason' => $reason->value],
        );
    }

    /**
     * Finish (or resume) a ticket hand-over: mark the ticket escalated if that
     * did not commit yet, run the idempotent side effects, close the turn.
     */
    protected function resumeTicketHandoff(Ticket $ticket, AiTurn $turn): AiTurn
    {
        $this->container->make(EscalateTicket::class)->handle(
            $ticket->refresh(),
            $turn->escalation_reason ?? EscalationReason::AiError,
            notifyCustomer: $turn->mode === AiMode::Auto,
            resume: true,
        );

        $turn->forceFill(['status' => AiTurn::STATUS_DONE])->save();

        return $turn;
    }

    /**
     * Finish (or resume) a conversation hand-over: the conversation becomes a
     * ticket (or, if it already did, that ticket's hand-over completes).
     */
    protected function resumeConversationHandoff(Conversation $conversation, AiTurn $turn): AiTurn
    {
        $conversation->refresh();
        $reason = $turn->escalation_reason ?? EscalationReason::AiError;

        $ticket = $conversation->ticket_id !== null
            ? Ticketing::ticketModel()::query()->withoutTenancy()->find($conversation->ticket_id)
            : null;

        $ticket instanceof Ticket
            ? $this->container->make(EscalateTicket::class)->handle($ticket, $reason, notifyCustomer: true, resume: true)
            : $this->conversations()->escalate($conversation, $reason);

        $turn->forceFill(['status' => AiTurn::STATUS_DONE])->save();

        return $turn;
    }

    /**
     * A hand-over this trigger recorded but never finished, taken back by a
     * retry of the job — or by anyone once older than `ai.turn_timeout`.
     */
    protected function pendingHandoff(string $triggerKey, bool $retry): ?AiTurn
    {
        $model = Ticketing::aiTurnModel();
        $turn = $model::query()->withoutTenancy()->where('trigger_key', $triggerKey)->first();

        if (! $turn instanceof AiTurn || $turn->status !== AiTurn::STATUS_HANDOFF) {
            return null;
        }

        return $this->reclaim($turn, $retry) ? $turn->refresh() : null;
    }

    /**
     * Atomically take an unfinished turn back (the attempts counter makes the
     * update change a row on every engine).
     */
    protected function reclaim(AiTurn $turn, bool $retry): bool
    {
        $timeout = max(30, (int) config('ticketing.ai.turn_timeout', 300));

        return Ticketing::aiTurnModel()::query()->withoutTenancy()
            ->whereKey($turn->getKey())
            ->where('status', $turn->status)
            ->when(! $retry, fn ($query) => $query->where('updated_at', '<', now()->subSeconds($timeout)))
            ->update(['updated_at' => now(), 'attempts' => DB::raw('attempts + 1')]) === 1;
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
                'status' => AiTurn::STATUS_RUNNING,
                'attempts' => 1,
                'driver' => $this->ai->defaultDriver(),
                'question' => mb_substr($plain, 0, 500),
                'question_fingerprint' => self::fingerprint($plain),
            ]),
        );

        if ($turn->wasRecentlyCreated) {
            return $turn;
        }

        if ($turn->status !== AiTurn::STATUS_RUNNING) {
            return null;
        }

        return $this->reclaim($turn, $retry) ? $turn->refresh() : null;
    }

    protected function finish(AiTurn $turn, AiOutcome $outcome, ?EscalationReason $reason, ?AiAnswer $answer, AiUsage $usage, int $started, ?string $error = null, string $status = AiTurn::STATUS_DONE): void
    {
        $turn->forceFill([
            'status' => $status,
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

        $suggested = $classification->priority;

        if ($suggested instanceof Priority && config('ticketing.ai.classification.apply_priority', true) !== false) {
            // Raise-only, compared with the stored priority under the row lock:
            // an agent may have raised it while the classifier was running.
            DB::transaction(function () use ($ticket, $suggested): void {
                /** @var Ticket $locked */
                $locked = Ticketing::ticketModel()::query()->withoutTenancy()
                    ->lockForUpdate()
                    ->whereKey($ticket->getKey())
                    ->firstOrFail();

                if ($suggested->value > $locked->priority->value) {
                    app(Ticketing::class)->changePriority($locked, $suggested);
                }
            });
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
