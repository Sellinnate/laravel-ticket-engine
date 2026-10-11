<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Selli\Ticketing\Actions\AddAttachment;
use Selli\Ticketing\Actions\ApplyMacro;
use Selli\Ticketing\Actions\AssignTicket;
use Selli\Ticketing\Actions\ChangePriority;
use Selli\Ticketing\Actions\EscalateTicket;
use Selli\Ticketing\Actions\MergeTickets;
use Selli\Ticketing\Actions\OpenTicket;
use Selli\Ticketing\Actions\PostMessage;
use Selli\Ticketing\Actions\RequestCsat;
use Selli\Ticketing\Actions\SplitTicket;
use Selli\Ticketing\Actions\SubmitCsat;
use Selli\Ticketing\Actions\TakeOverTicket;
use Selli\Ticketing\Actions\TransitionTicket;
use Selli\Ticketing\Ai\AiManager;
use Selli\Ticketing\Contacts\ContactDirectory;
use Selli\Ticketing\Conversations\ConversationService;
use Selli\Ticketing\Conversations\WidgetKeys;
use Selli\Ticketing\Data\AddAttachmentData;
use Selli\Ticketing\Data\AssignTicketData;
use Selli\Ticketing\Data\OpenTicketData;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Data\TransitionData;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Enums\TicketChannel;
use Selli\Ticketing\Exceptions\CsatException;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Gdpr\AnonymizeRequester;
use Selli\Ticketing\Gdpr\ExportRequesterData;
use Selli\Ticketing\Knowledge\KnowledgeBase;
use Selli\Ticketing\Mail\InboundEmail;
use Selli\Ticketing\Mail\MailboxManager;
use Selli\Ticketing\Mail\ProcessInboundEmail;
use Selli\Ticketing\Models\AiTurn;
use Selli\Ticketing\Models\AutomationRule;
use Selli\Ticketing\Models\BusinessHours;
use Selli\Ticketing\Models\CannedResponse;
use Selli\Ticketing\Models\Contact;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\ConversationMessage;
use Selli\Ticketing\Models\Holiday;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\KbArticleFeedback;
use Selli\Ticketing\Models\KbArticleLink;
use Selli\Ticketing\Models\KbArticleVersion;
use Selli\Ticketing\Models\KbCategory;
use Selli\Ticketing\Models\Macro;
use Selli\Ticketing\Models\RoutingRule;
use Selli\Ticketing\Models\SatisfactionRating;
use Selli\Ticketing\Models\SlaClock;
use Selli\Ticketing\Models\SlaPolicy;
use Selli\Ticketing\Models\Tag;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\TeamMember;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketActivity;
use Selli\Ticketing\Models\TicketAttachment;
use Selli\Ticketing\Models\TicketLink;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Models\WidgetKey;
use Selli\Ticketing\Query\TicketQuery;
use Selli\Ticketing\Reporting\Metrics;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * The primary entry point to the domain. Resolved as a singleton and exposed
 * via the Ticketing facade. Heavy lifting lives in the Action classes so the
 * same operations stay invocable from jobs, commands and listeners.
 *
 * Model bindings are overridable the same way Cashier/Commerce allow it:
 * `Ticketing::useTicketModel(MyTicket::class)`.
 */
class Ticketing
{
    /** @var array<string, class-string<Model>> */
    protected static array $models = [];

    public function __construct(protected Container $container) {}

    /**
     * Open a new ticket. Prefer `for($subject)->open(...)` for subject tickets.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(
        string $type,
        string $title,
        mixed $requester = null,
        ?Priority $priority = null,
        mixed $subject = null,
        ?string $category = null,
        array $attributes = [],
        TicketChannel|string|null $channel = null,
    ): Ticket {
        return $this->container->make(OpenTicket::class)->handle(new OpenTicketData(
            type: $type,
            title: $title,
            requester: $requester,
            priority: $priority,
            subject: $subject,
            category: $category,
            attributes: $attributes,
            channel: $channel instanceof TicketChannel ? $channel->value : $channel,
        ));
    }

    /**
     * Begin a fluent operation against a subject (to open) or an existing
     * ticket (to act on it).
     */
    public function for(mixed $target): PendingTicket
    {
        return new PendingTicket($this, $target);
    }

    /**
     * A filterable, sortable listing of the current tenant's tickets.
     */
    public function tickets(): TicketQuery
    {
        return TicketQuery::make();
    }

    /**
     * Post a message to a ticket.
     *
     * @param  array<string, mixed>  $meta
     */
    public function postMessage(
        Ticket $ticket,
        ?Model $author,
        string $body,
        MessageVisibility $visibility = MessageVisibility::Public,
        array $meta = [],
    ): TicketMessage {
        return $this->container->make(PostMessage::class)->handle(new PostMessageData(
            ticket: $ticket,
            author: $author,
            body: $body,
            visibility: $visibility,
            meta: $meta,
        ));
    }

    /**
     * Apply a workflow transition to a ticket.
     *
     * @param  array<string, mixed>  $params
     */
    public function transition(
        Ticket $ticket,
        string $transition,
        ?Model $actor = null,
        ?string $note = null,
        array $params = [],
    ): Ticket {
        return $this->container->make(TransitionTicket::class)->handle(new TransitionData(
            ticket: $ticket,
            transition: $transition,
            actor: $actor,
            note: $note,
            params: $params,
        ));
    }

    /**
     * Assign a ticket to an agent and/or a team (with an optional strategy).
     */
    public function assign(
        Ticket $ticket,
        ?Model $assignee = null,
        ?Team $team = null,
        ?string $strategy = null,
        ?Model $actor = null,
    ): Ticket {
        return $this->container->make(AssignTicket::class)->handle(new AssignTicketData(
            ticket: $ticket,
            assignee: $assignee,
            team: $team,
            strategy: $strategy,
            actor: $actor,
        ));
    }

    /**
     * Add an attachment (uploaded file) to a ticket or message.
     */
    public function addAttachment(
        Model $attachable,
        UploadedFile $file,
        ?string $disk = null,
        ?Model $uploadedBy = null,
    ): TicketAttachment {
        return $this->container->make(AddAttachment::class)->handle(
            new AddAttachmentData($attachable, $file, $disk, $uploadedBy),
        );
    }

    /**
     * Tag a ticket, creating the tags (per-tenant) as needed.
     *
     * @param  list<string>  $names
     */
    public function tag(Ticket $ticket, array $names): Ticket
    {
        $tagModel = static::tagModel();
        $ids = [];

        foreach ($names as $name) {
            $slug = Str::slug($name);
            $tenantColumn = $ticket->getTenantColumn();

            // firstOrCreate is race-safe against the (tenant, slug) unique index:
            // two concurrent taggings of the same new tag won't throw a unique
            // violation that would roll back the surrounding transaction.
            $tag = $tagModel::query()->withoutTenancy()->firstOrCreate(
                [
                    'slug' => $slug,
                    $tenantColumn => $ticket->getAttribute($tenantColumn),
                ],
                array_merge($ticket->tenantAttributes(), ['name' => $name, 'slug' => $slug]),
            );

            $ids[] = $tag->getKey();
        }

        // Attach under the ticket's own tenant context so the pivot sync is
        // never affected by a missing/foreign ambient tenant (the tags() scope
        // is already constrained by the parent ticket).
        $this->inTicketTenant($ticket, fn () => $ticket->tags()->syncWithoutDetaching($ids));

        return $ticket;
    }

    /**
     * Remove tags from a ticket by name.
     *
     * @param  list<string>  $names
     */
    public function untag(Ticket $ticket, array $names): Ticket
    {
        $slugs = array_map(fn (string $name): string => Str::slug($name), $names);

        $ids = static::tagModel()::query()->withoutTenancy()
            ->whereIn('slug', $slugs)
            ->where($ticket->getTenantColumn(), $ticket->getAttribute($ticket->getTenantColumn()))
            ->pluck((new (static::tagModel()))->getKeyName())
            ->all();

        $this->inTicketTenant($ticket, fn () => $ticket->tags()->detach($ids));

        return $ticket;
    }

    /**
     * Run a closure in the given ticket's tenant context, so relationship pivot
     * operations resolve against the ticket's tenant regardless of the ambient
     * context (queue/CLI/cross-tenant admin).
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function inTicketTenant(Ticket $ticket, \Closure $callback): mixed
    {
        return $this->container->make(TenantContext::class)->forTenant(
            $ticket->getAttribute($ticket->getTenantColumn()),
            $callback,
        );
    }

    /**
     * Apply a macro (transition + assignment + reply + tags) to a ticket.
     */
    public function applyMacro(Ticket $ticket, Macro $macro, ?Model $actor = null): Ticket
    {
        return $this->container->make(ApplyMacro::class)->handle($ticket, $macro, $actor);
    }

    /**
     * Merge source tickets into a target.
     *
     * @param  iterable<Ticket>  $sources
     */
    public function merge(Ticket $target, iterable $sources, ?Model $actor = null): Ticket
    {
        return $this->container->make(MergeTickets::class)->handle($target, $sources, $actor);
    }

    /**
     * Split messages out of a ticket into a new one.
     *
     * @param  list<int|string>  $messageIds
     */
    public function split(Ticket $source, array $messageIds, ?string $title = null, ?Model $actor = null): Ticket
    {
        return $this->container->make(SplitTicket::class)->handle($source, $messageIds, $title, $actor);
    }

    /**
     * Change a ticket's priority (audited, emits PriorityChanged).
     */
    public function changePriority(Ticket $ticket, Priority $priority, ?Model $actor = null): Ticket
    {
        return $this->container->make(ChangePriority::class)->handle($ticket, $priority, $actor);
    }

    /**
     * (Re-)request a satisfaction rating for a ticket, emitting CsatRequested.
     */
    public function requestCsat(Ticket $ticket, ?Model $actor = null): SatisfactionRating
    {
        return $this->container->make(RequestCsat::class)->handle($ticket, $actor);
    }

    /**
     * Submit (or update) the satisfaction rating for a ticket.
     */
    public function submitCsat(Ticket $ticket, int $rating, ?string $comment = null, ?Model $submittedBy = null): SatisfactionRating
    {
        return $this->container->make(SubmitCsat::class)->handle($ticket, $rating, $comment, $submittedBy);
    }

    /**
     * Submit a satisfaction rating from a signed token (e.g. the link in a CSAT
     * email), resolving the ticket the token names. Fails closed on an invalid
     * or expired token.
     */
    public function submitCsatByToken(string $token, int $rating, ?string $comment = null, ?Model $submittedBy = null): SatisfactionRating
    {
        $claims = CsatToken::verify($token);

        if ($claims === null) {
            throw CsatException::invalidToken();
        }

        $ticket = static::ticketModel()::query()->withoutTenancy()->find($claims['ticket']);

        if (! $ticket instanceof Ticket) {
            // An orphaned link (ticket deleted) is just an invalid token, not a
            // 404 — keep the fail-closed contract consistent.
            throw CsatException::invalidToken();
        }

        // The token is a bearer credential bound to its request cycle: one
        // valuation per cycle (allowOverwrite: false) and the cycle is verified
        // UNDER the ticket lock inside SubmitCsat, so a concurrent re-arm can't
        // be raced past the stale-token check.
        return $this->container->make(SubmitCsat::class)->handle(
            $ticket,
            $rating,
            $comment,
            $submittedBy,
            allowOverwrite: false,
            expectedCycle: $claims['cycle'],
        );
    }

    /**
     * Issue a fresh signed CSAT token for a ticket (e.g. to embed in a host URL),
     * bound to the ticket's current request cycle when a rating row exists.
     */
    public function csatToken(Ticket $ticket, ?int $ttl = null): string
    {
        $existing = static::satisfactionRatingModel()::query()->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->first();

        $cycle = $existing instanceof SatisfactionRating ? (string) $existing->cycle : '';

        return CsatToken::issue($ticket->getKey(), now()->addSeconds($ttl ?? Csat::tokenTtl()), $cycle);
    }

    /**
     * Ingest a normalised inbound email — open a ticket or thread a reply. Loop-
     * and duplicate-safe; returns the created message, or null when the email is
     * dropped (disabled, auto-reply, rate limited, duplicate, or unroutable).
     */
    public function receiveEmail(InboundEmail $email): ?TicketMessage
    {
        return $this->container->make(ProcessInboundEmail::class)->handle($email);
    }

    /**
     * The tagged Reply-To address for a ticket (support+t_<token>@…), so a
     * customer's reply threads straight back to it. Null when no reply-to base
     * is configured.
     */
    public function replyAddressFor(Ticket $ticket): ?string
    {
        $base = config('ticketing.mail.outbound.reply_to_base');

        // Require a real address (with an @): tagAddress() returns its input
        // unchanged when there's no @, so a base like "support" or a stray space
        // would otherwise yield a broken Reply-To instead of disabling the tag.
        if (! is_string($base) || ! str_contains($base = trim($base), '@')) {
            return null;
        }

        try {
            $token = MailThreadToken::issue($ticket->getKey());
        } catch (InvalidConfigurationException $exception) {
            // No token secret configured: degrade to no tagged Reply-To rather
            // than crashing a (possibly queued) outbound notification.
            report($exception);

            return null;
        }

        return MailThreadToken::tagAddress($base, $token);
    }

    /**
     * Scrub the personal data the package stores for a requester (GDPR §17.4),
     * keeping the tickets for statistics. Returns the number of tickets touched
     * and emits RequesterAnonymized for the host to anonymise its own model.
     */
    public function anonymiseRequester(Model $requester): int
    {
        return $this->container->make(AnonymizeRequester::class)->handle($requester);
    }

    /**
     * A data-subject export for a requester: their tickets, public conversation
     * and satisfaction ratings.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRequesterData(Model $requester): array
    {
        return $this->container->make(ExportRequesterData::class)->handle($requester);
    }

    /**
     * An agent takes a ticket over: the AI stops on it for good, and an
     * unassigned ticket is assigned to the agent (unless $assign is false).
     */
    public function takeOver(Ticket $ticket, Model $agent, bool $assign = true): Ticket
    {
        return $this->container->make(TakeOverTicket::class)->handle($ticket, $agent, $assign);
    }

    /**
     * Hand a ticket to humans (routing fallback, team-lead notification, AI
     * summary). The reason defaults to an explicit, manual escalation.
     */
    public function escalate(
        Ticket $ticket,
        EscalationReason $reason = EscalationReason::Manual,
        ?Model $actor = null,
        bool $notifyCustomer = false,
    ): Ticket {
        return $this->container->make(EscalateTicket::class)->handle($ticket, $reason, $actor, $notifyCustomer);
    }

    /**
     * Support KPIs for a period (the last 30 days by default), for the current
     * tenant: `Ticketing::metrics()->between($from, $to)->filter([...])->summary()`.
     */
    public function metrics(): Metrics
    {
        return new Metrics;
    }

    /**
     * The inbound mailboxes polled by `ticketing:mail:poll` (extend() adds a
     * driver next to the built-in imap one).
     */
    public function mailboxes(): MailboxManager
    {
        return $this->container->make(MailboxManager::class);
    }

    /**
     * External contacts (requesters without an account in the host app).
     */
    public function contacts(): ContactDirectory
    {
        return $this->container->make(ContactDirectory::class);
    }

    /**
     * The headless knowledge base (articles, FAQs, search, feedback).
     */
    public function knowledge(): KnowledgeBase
    {
        return $this->container->make(KnowledgeBase::class);
    }

    /**
     * Widget/chat conversations that turn into tickets.
     */
    public function conversations(): ConversationService
    {
        return $this->container->make(ConversationService::class);
    }

    /**
     * Public widget keys (issue, rotate, revoke, verify identities).
     */
    public function widgetKeys(): WidgetKeys
    {
        return $this->container->make(WidgetKeys::class);
    }

    /**
     * The AI driver manager (resolve or register drivers).
     */
    public function ai(): AiManager
    {
        return $this->container->make(AiManager::class);
    }

    // --- Model binding -----------------------------------------------------

    /**
     * @param  class-string<Ticket>  $model
     */
    public static function useTicketModel(string $model): void
    {
        static::$models['ticket'] = $model;
    }

    /**
     * @param  class-string<TicketType>  $model
     */
    public static function useTicketTypeModel(string $model): void
    {
        static::$models['ticket_type'] = $model;
    }

    /**
     * @param  class-string<TicketMessage>  $model
     */
    public static function useTicketMessageModel(string $model): void
    {
        static::$models['ticket_message'] = $model;
    }

    /**
     * @param  class-string<TicketParticipant>  $model
     */
    public static function useTicketParticipantModel(string $model): void
    {
        static::$models['ticket_participant'] = $model;
    }

    /**
     * @param  class-string<TicketActivity>  $model
     */
    public static function useTicketActivityModel(string $model): void
    {
        static::$models['ticket_activity'] = $model;
    }

    /**
     * @param  class-string<SlaPolicy>  $model
     */
    public static function useSlaPolicyModel(string $model): void
    {
        static::$models['sla_policy'] = $model;
    }

    /**
     * @param  class-string<SlaClock>  $model
     */
    public static function useSlaClockModel(string $model): void
    {
        static::$models['sla_clock'] = $model;
    }

    /**
     * @param  class-string<BusinessHours>  $model
     */
    public static function useBusinessHoursModel(string $model): void
    {
        static::$models['business_hours'] = $model;
    }

    /**
     * @param  class-string<Holiday>  $model
     */
    public static function useHolidayModel(string $model): void
    {
        static::$models['holiday'] = $model;
    }

    /**
     * @param  class-string<Team>  $model
     */
    public static function useTeamModel(string $model): void
    {
        static::$models['team'] = $model;
    }

    /**
     * @param  class-string<TeamMember>  $model
     */
    public static function useTeamMemberModel(string $model): void
    {
        static::$models['team_member'] = $model;
    }

    /**
     * @param  class-string<RoutingRule>  $model
     */
    public static function useRoutingRuleModel(string $model): void
    {
        static::$models['routing_rule'] = $model;
    }

    /**
     * @param  class-string<TicketAttachment>  $model
     */
    public static function useTicketAttachmentModel(string $model): void
    {
        static::$models['ticket_attachment'] = $model;
    }

    /**
     * @param  class-string<CannedResponse>  $model
     */
    public static function useCannedResponseModel(string $model): void
    {
        static::$models['canned_response'] = $model;
    }

    /**
     * @param  class-string<Macro>  $model
     */
    public static function useMacroModel(string $model): void
    {
        static::$models['macro'] = $model;
    }

    /**
     * @param  class-string<Tag>  $model
     */
    public static function useTagModel(string $model): void
    {
        static::$models['tag'] = $model;
    }

    /**
     * @param  class-string<TicketLink>  $model
     */
    public static function useTicketLinkModel(string $model): void
    {
        static::$models['ticket_link'] = $model;
    }

    /**
     * @param  class-string<SatisfactionRating>  $model
     */
    public static function useSatisfactionRatingModel(string $model): void
    {
        static::$models['satisfaction_rating'] = $model;
    }

    /**
     * @param  class-string<AutomationRule>  $model
     */
    public static function useAutomationRuleModel(string $model): void
    {
        static::$models['automation_rule'] = $model;
    }

    /**
     * @param  class-string<Contact>  $model
     */
    public static function useContactModel(string $model): void
    {
        static::$models['contact'] = $model;
    }

    /**
     * @param  class-string<KbCategory>  $model
     */
    public static function useKbCategoryModel(string $model): void
    {
        static::$models['kb_category'] = $model;
    }

    /**
     * @param  class-string<KbArticle>  $model
     */
    public static function useKbArticleModel(string $model): void
    {
        static::$models['kb_article'] = $model;
    }

    /**
     * @param  class-string<KbArticleVersion>  $model
     */
    public static function useKbArticleVersionModel(string $model): void
    {
        static::$models['kb_article_version'] = $model;
    }

    /**
     * @param  class-string<KbArticleFeedback>  $model
     */
    public static function useKbArticleFeedbackModel(string $model): void
    {
        static::$models['kb_article_feedback'] = $model;
    }

    /**
     * @param  class-string<KbArticleLink>  $model
     */
    public static function useKbArticleLinkModel(string $model): void
    {
        static::$models['kb_article_link'] = $model;
    }

    /**
     * @param  class-string<WidgetKey>  $model
     */
    public static function useWidgetKeyModel(string $model): void
    {
        static::$models['widget_key'] = $model;
    }

    /**
     * @param  class-string<Conversation>  $model
     */
    public static function useConversationModel(string $model): void
    {
        static::$models['conversation'] = $model;
    }

    /**
     * @param  class-string<ConversationMessage>  $model
     */
    public static function useConversationMessageModel(string $model): void
    {
        static::$models['conversation_message'] = $model;
    }

    /**
     * @param  class-string<AiTurn>  $model
     */
    public static function useAiTurnModel(string $model): void
    {
        static::$models['ai_turn'] = $model;
    }

    /**
     * Enable ULID primary keys across the package tables.
     */
    public static function useUlids(bool $enabled = true): void
    {
        config(['ticketing.ids.type' => $enabled ? 'ulid' : 'auto']);
    }

    /**
     * Reset in-memory model overrides (used by the test suite).
     */
    public static function flushModelBindings(): void
    {
        static::$models = [];
    }

    /**
     * @return class-string<Ticket>
     */
    public static function ticketModel(): string
    {
        /** @var class-string<Ticket> */
        return static::$models['ticket'] ?? config('ticketing.models.ticket', Ticket::class);
    }

    /**
     * @return class-string<TicketType>
     */
    public static function ticketTypeModel(): string
    {
        /** @var class-string<TicketType> */
        return static::$models['ticket_type'] ?? config('ticketing.models.ticket_type', TicketType::class);
    }

    /**
     * @return class-string<TicketMessage>
     */
    public static function ticketMessageModel(): string
    {
        /** @var class-string<TicketMessage> */
        return static::$models['ticket_message'] ?? config('ticketing.models.ticket_message', TicketMessage::class);
    }

    /**
     * @return class-string<TicketParticipant>
     */
    public static function ticketParticipantModel(): string
    {
        /** @var class-string<TicketParticipant> */
        return static::$models['ticket_participant'] ?? config('ticketing.models.ticket_participant', TicketParticipant::class);
    }

    /**
     * @return class-string<TicketActivity>
     */
    public static function ticketActivityModel(): string
    {
        /** @var class-string<TicketActivity> */
        return static::$models['ticket_activity'] ?? config('ticketing.models.ticket_activity', TicketActivity::class);
    }

    /**
     * @return class-string<SlaPolicy>
     */
    public static function slaPolicyModel(): string
    {
        /** @var class-string<SlaPolicy> */
        return static::$models['sla_policy'] ?? config('ticketing.models.sla_policy', SlaPolicy::class);
    }

    /**
     * @return class-string<SlaClock>
     */
    public static function slaClockModel(): string
    {
        /** @var class-string<SlaClock> */
        return static::$models['sla_clock'] ?? config('ticketing.models.sla_clock', SlaClock::class);
    }

    /**
     * @return class-string<BusinessHours>
     */
    public static function businessHoursModel(): string
    {
        /** @var class-string<BusinessHours> */
        return static::$models['business_hours'] ?? config('ticketing.models.business_hours', BusinessHours::class);
    }

    /**
     * @return class-string<Holiday>
     */
    public static function holidayModel(): string
    {
        /** @var class-string<Holiday> */
        return static::$models['holiday'] ?? config('ticketing.models.holiday', Holiday::class);
    }

    /**
     * @return class-string<Team>
     */
    public static function teamModel(): string
    {
        /** @var class-string<Team> */
        return static::$models['team'] ?? config('ticketing.models.team', Team::class);
    }

    /**
     * @return class-string<TeamMember>
     */
    public static function teamMemberModel(): string
    {
        /** @var class-string<TeamMember> */
        return static::$models['team_member'] ?? config('ticketing.models.team_member', TeamMember::class);
    }

    /**
     * @return class-string<RoutingRule>
     */
    public static function routingRuleModel(): string
    {
        /** @var class-string<RoutingRule> */
        return static::$models['routing_rule'] ?? config('ticketing.models.routing_rule', RoutingRule::class);
    }

    /**
     * @return class-string<TicketAttachment>
     */
    public static function ticketAttachmentModel(): string
    {
        /** @var class-string<TicketAttachment> */
        return static::$models['ticket_attachment'] ?? config('ticketing.models.ticket_attachment', TicketAttachment::class);
    }

    /**
     * @return class-string<CannedResponse>
     */
    public static function cannedResponseModel(): string
    {
        /** @var class-string<CannedResponse> */
        return static::$models['canned_response'] ?? config('ticketing.models.canned_response', CannedResponse::class);
    }

    /**
     * @return class-string<Macro>
     */
    public static function macroModel(): string
    {
        /** @var class-string<Macro> */
        return static::$models['macro'] ?? config('ticketing.models.macro', Macro::class);
    }

    /**
     * @return class-string<Tag>
     */
    public static function tagModel(): string
    {
        /** @var class-string<Tag> */
        return static::$models['tag'] ?? config('ticketing.models.tag', Tag::class);
    }

    /**
     * @return class-string<TicketLink>
     */
    public static function ticketLinkModel(): string
    {
        /** @var class-string<TicketLink> */
        return static::$models['ticket_link'] ?? config('ticketing.models.ticket_link', TicketLink::class);
    }

    /**
     * @return class-string<SatisfactionRating>
     */
    public static function satisfactionRatingModel(): string
    {
        /** @var class-string<SatisfactionRating> */
        return static::$models['satisfaction_rating'] ?? config('ticketing.models.satisfaction_rating', SatisfactionRating::class);
    }

    /**
     * @return class-string<AutomationRule>
     */
    public static function automationRuleModel(): string
    {
        /** @var class-string<AutomationRule> */
        return static::$models['automation_rule'] ?? config('ticketing.models.automation_rule', AutomationRule::class);
    }

    /**
     * @return class-string<Contact>
     */
    public static function contactModel(): string
    {
        /** @var class-string<Contact> */
        return static::$models['contact'] ?? config('ticketing.models.contact', Contact::class);
    }

    /**
     * @return class-string<KbCategory>
     */
    public static function kbCategoryModel(): string
    {
        /** @var class-string<KbCategory> */
        return static::$models['kb_category'] ?? config('ticketing.models.kb_category', KbCategory::class);
    }

    /**
     * @return class-string<KbArticle>
     */
    public static function kbArticleModel(): string
    {
        /** @var class-string<KbArticle> */
        return static::$models['kb_article'] ?? config('ticketing.models.kb_article', KbArticle::class);
    }

    /**
     * @return class-string<KbArticleVersion>
     */
    public static function kbArticleVersionModel(): string
    {
        /** @var class-string<KbArticleVersion> */
        return static::$models['kb_article_version'] ?? config('ticketing.models.kb_article_version', KbArticleVersion::class);
    }

    /**
     * @return class-string<KbArticleFeedback>
     */
    public static function kbArticleFeedbackModel(): string
    {
        /** @var class-string<KbArticleFeedback> */
        return static::$models['kb_article_feedback'] ?? config('ticketing.models.kb_article_feedback', KbArticleFeedback::class);
    }

    /**
     * @return class-string<KbArticleLink>
     */
    public static function kbArticleLinkModel(): string
    {
        /** @var class-string<KbArticleLink> */
        return static::$models['kb_article_link'] ?? config('ticketing.models.kb_article_link', KbArticleLink::class);
    }

    /**
     * @return class-string<WidgetKey>
     */
    public static function widgetKeyModel(): string
    {
        /** @var class-string<WidgetKey> */
        return static::$models['widget_key'] ?? config('ticketing.models.widget_key', WidgetKey::class);
    }

    /**
     * @return class-string<Conversation>
     */
    public static function conversationModel(): string
    {
        /** @var class-string<Conversation> */
        return static::$models['conversation'] ?? config('ticketing.models.conversation', Conversation::class);
    }

    /**
     * @return class-string<ConversationMessage>
     */
    public static function conversationMessageModel(): string
    {
        /** @var class-string<ConversationMessage> */
        return static::$models['conversation_message'] ?? config('ticketing.models.conversation_message', ConversationMessage::class);
    }

    /**
     * @return class-string<AiTurn>
     */
    public static function aiTurnModel(): string
    {
        /** @var class-string<AiTurn> */
        return static::$models['ai_turn'] ?? config('ticketing.models.ai_turn', AiTurn::class);
    }

    /**
     * Instantiate a fresh instance of a bound model.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    public static function make(string $model): object
    {
        return new $model;
    }
}
