<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Ai\AiRequestFactory;
use Selli\Ticketing\Ai\TicketSummarizer;
use Selli\Ticketing\Data\AssignTicketData;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Events\TicketEscalated;
use Selli\Ticketing\Exceptions\EscalationNotAllowedException;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Routing\RoutingEngine;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;

/**
 * Hands a ticket to humans: stops the AI on it, records why, makes sure someone
 * owns it (routing rules, then the configured escalation team), optionally
 * tells the customer and leaves an AI summary for the agent, then emits
 * {@see TicketEscalated} — which notifies the assignee and the team leads.
 *
 * Escalating a ticket an agent has already taken over is a no-op, and so is
 * escalating it twice — unless $resume asks to finish a hand-over whose side
 * effects were interrupted (a crashed worker): every step is idempotent, so a
 * resumed hand-over never posts the customer message or the summary twice.
 *
 * A resolved or closed ticket cannot be escalated (reopen it first): a resumed
 * hand-over on one just stops.
 */
class EscalateTicket
{
    public function __construct(
        protected AuditLogger $audit,
        protected Container $container,
    ) {}

    public function handle(Ticket $ticket, EscalationReason $reason, ?Model $actor = null, bool $notifyCustomer = true, bool $resume = false): Ticket
    {
        $result = DB::transaction(function () use ($ticket, $reason, $actor, $resume): ?Ticket {
            /** @var Ticket $locked */
            $locked = Ticketing::ticketModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->whereKey($ticket->getKey())
                ->firstOrFail();

            // A human already owns the conversation: nothing to hand over, and
            // the AI must not override the takeover.
            if ($locked->ai_status === AiStatus::Suspended) {
                return null;
            }

            // Checked first, so no entry point (REST, facade, a resumed AI
            // hand-over) promises a human on a ticket that stays closed.
            if ($this->container->make(AiRequestFactory::class)->isClosed($locked)) {
                if ($resume) {
                    return null;
                }

                throw EscalationNotAllowedException::closed();
            }

            if ($locked->ai_status === AiStatus::Escalated) {
                return $resume ? $locked : null;
            }

            self::mark($locked, $reason);

            $this->audit->record(
                ticket: $locked,
                event: 'ticket.escalated',
                actor: $actor,
                context: ['reason' => $reason->value],
            );

            return $locked;
        });

        if ($result === null) {
            return $ticket->refresh();
        }

        return $this->handOver($result, $reason, $actor, $notifyCustomer);
    }

    /**
     * The state change alone, on a ticket the caller holds locked — so it can
     * commit together with the caller's own writes.
     *
     * @internal
     */
    public static function mark(Ticket $locked, EscalationReason $reason): void
    {
        $locked->forceFill([
            'ai_status' => AiStatus::Escalated,
            'escalation_reason' => $reason,
            'escalated_at' => $locked->escalated_at ?? now(),
        ])->save();
    }

    /**
     * The side effects of an escalation, each idempotent: an owner, the
     * customer hand-off message, the agent summary, then the event (which
     * notifies the assignee and the team leads).
     *
     * @internal
     */
    public function handOver(Ticket $ticket, EscalationReason $reason, ?Model $actor = null, bool $notifyCustomer = true): Ticket
    {
        $escalated = $this->ensureOwner($ticket, $actor);

        $text = config('ticketing.ai.escalation.handoff_message');

        if ($notifyCustomer && is_string($text) && trim($text) !== '') {
            $this->postOnce($escalated, 'handoff', fn () => $this->container->make(PostMessage::class)->handle(new PostMessageData(
                ticket: $escalated,
                author: null,
                body: $text,
                visibility: MessageVisibility::Public,
                source: MessageSource::Ai,
                meta: ['ai' => ['kind' => 'handoff']],
            )));
        }

        if (config('ticketing.ai.escalation.summarize', true) !== false && ! $this->alreadyPosted($escalated, 'summary')) {
            // The model works without any lock; only the posting is claimed.
            $summarizer = $this->container->make(TicketSummarizer::class);
            $summary = $summarizer->generate($escalated);

            if ($summary !== null) {
                $this->postOnce($escalated, 'summary', fn () => $summarizer->post($escalated, $summary));
            }
        }

        $escalated->refresh();

        // Announced once per escalation: the marker is written in the same
        // transaction that dispatches the (after-commit) event, so a resumed
        // hand-over does not notify the team a second time.
        DB::transaction(function () use ($escalated, $reason, $actor): void {
            Ticketing::ticketModel()::query()->withoutTenancy()->lockForUpdate()->whereKey($escalated->getKey())->first();

            if ($this->announced($escalated)) {
                return;
            }

            $this->audit->record(
                ticket: $escalated,
                event: 'ticket.escalation_announced',
                actor: $actor,
                context: ['reason' => $reason->value],
            );

            TicketEscalated::dispatch($escalated, $reason, $actor);
        });

        return $escalated;
    }

    /**
     * Whether the current escalation was already announced.
     */
    protected function announced(Ticket $ticket): bool
    {
        return Ticketing::ticketActivityModel()::query()->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->where('event', 'ticket.escalation_announced')
            ->when($ticket->escalated_at !== null, fn ($query) => $query->where('created_at', '>=', $ticket->escalated_at))
            ->exists();
    }

    /**
     * Whether an AI message of this kind was already posted for the current
     * escalation (a resumed hand-over must not repeat it).
     */
    protected function alreadyPosted(Ticket $ticket, string $kind): bool
    {
        return Ticketing::ticketMessageModel()::query()->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->where('meta->ai->kind', $kind)
            ->when($ticket->escalated_at !== null, fn ($query) => $query->where('created_at', '>=', $ticket->escalated_at))
            ->exists();
    }

    /**
     * Routing rules first; when they find no owner, the configured escalation
     * team (by key or id) with its default strategy.
     */
    protected function ensureOwner(Ticket $ticket, ?Model $actor): Ticket
    {
        // Decided and assigned under the ticket lock, on the stored owner: an
        // agent who assigned or took the ticket meanwhile is never overwritten.
        return DB::transaction(function () use ($ticket, $actor): Ticket {
            /** @var Ticket $locked */
            $locked = Ticketing::ticketModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->whereKey($ticket->getKey())
                ->firstOrFail();

            if ($locked->assignee_id !== null || $locked->team_id !== null || $locked->ai_status === AiStatus::Suspended) {
                return $locked;
            }

            try {
                // Its own savepoint: a failing rule leaves the outer
                // transaction usable (PostgreSQL aborts it otherwise).
                $routed = DB::transaction(fn (): ?Ticket => $this->container->make(RoutingEngine::class)->route($locked, $actor));
            } catch (\Throwable $exception) {
                // A broken rule must not stop the hand-over itself.
                report($exception);
                $routed = null;
            }

            if ($routed instanceof Ticket && ($routed->assignee_id !== null || $routed->team_id !== null)) {
                return $routed;
            }

            $team = $this->escalationTeam($locked);

            if ($team === null) {
                return $locked;
            }

            return $this->container->make(AssignTicket::class)->handle(new AssignTicketData(
                ticket: $locked,
                team: $team,
                actor: $actor,
            ));
        });
    }

    protected function escalationTeam(Ticket $ticket): ?Team
    {
        $configured = config('ticketing.ai.escalation.team');

        if (! is_scalar($configured) || (string) $configured === '') {
            return null;
        }

        $tenantColumn = $ticket->getTenantColumn();

        $teams = Ticketing::teamModel()::query()->withoutTenancy()
            ->where('is_active', true)
            ->where(function ($query) use ($configured): void {
                $query->where('key', (string) $configured);

                // Or the primary key, in the configured id strategy.
                $keyName = (new (Ticketing::teamModel()))->getKeyName();

                if (config('ticketing.ids.type') === 'ulid') {
                    if (preg_match('/^[0-9A-Za-z]{26}$/', (string) $configured) === 1) {
                        $query->orWhere($keyName, (string) $configured);
                    }
                } elseif (filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                    $query->orWhere($keyName, (int) $configured);
                }
            })
            ->where(function ($query) use ($tenantColumn, $ticket): void {
                $query->where($tenantColumn, $ticket->getAttribute($tenantColumn));

                if (config('ticketing.tenancy.allow_shared', true) !== false) {
                    $query->orWhereNull($tenantColumn);
                }
            })
            ->get();

        // The tenant's own team wins over a shared one with the same key.
        $team = $teams->sortBy(fn (Team $candidate): int => $candidate->getAttribute($tenantColumn) === null ? 1 : 0)->first();

        return $team instanceof Team ? $team : null;
    }

    /**
     * Post an escalation message at most once, even when two recoveries race:
     * the check and the post happen under the ticket's row lock.
     *
     * @param  \Closure(): mixed  $post
     */
    protected function postOnce(Ticket $ticket, string $kind, \Closure $post): void
    {
        DB::transaction(function () use ($ticket, $kind, $post): void {
            $locked = Ticketing::ticketModel()::query()->withoutTenancy()->lockForUpdate()->whereKey($ticket->getKey())->first();

            // An agent who took over meanwhile owns the conversation now, and a
            // ticket resolved meanwhile needs nobody: no AI hand-off message or
            // summary after that.
            if (! $locked instanceof Ticket || $locked->ai_status === AiStatus::Suspended
                || $this->container->make(AiRequestFactory::class)->isClosed($locked)) {
                return;
            }

            if (! $this->alreadyPosted($ticket, $kind)) {
                $post();
            }
        });
    }
}
