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
use Selli\Ticketing\Actions\MergeTickets;
use Selli\Ticketing\Actions\OpenTicket;
use Selli\Ticketing\Actions\PostMessage;
use Selli\Ticketing\Actions\RequestCsat;
use Selli\Ticketing\Actions\SplitTicket;
use Selli\Ticketing\Actions\SubmitCsat;
use Selli\Ticketing\Actions\TransitionTicket;
use Selli\Ticketing\Data\AddAttachmentData;
use Selli\Ticketing\Data\AssignTicketData;
use Selli\Ticketing\Data\OpenTicketData;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Data\TransitionData;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Exceptions\CsatException;
use Selli\Ticketing\Models\AutomationRule;
use Selli\Ticketing\Models\BusinessHours;
use Selli\Ticketing\Models\CannedResponse;
use Selli\Ticketing\Models\Holiday;
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
    /** @var array<string, class-string> */
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
    ): Ticket {
        return $this->container->make(OpenTicket::class)->handle(new OpenTicketData(
            type: $type,
            title: $title,
            requester: $requester,
            priority: $priority,
            subject: $subject,
            category: $category,
            attributes: $attributes,
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
     * Post a message to a ticket.
     *
     * @param  array<string, mixed>  $meta
     */
    public function postMessage(
        Ticket $ticket,
        mixed $author,
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

    // --- Model binding -----------------------------------------------------

    public static function useTicketModel(string $model): void
    {
        static::$models['ticket'] = $model;
    }

    public static function useTicketTypeModel(string $model): void
    {
        static::$models['ticket_type'] = $model;
    }

    public static function useTicketMessageModel(string $model): void
    {
        static::$models['ticket_message'] = $model;
    }

    public static function useTicketParticipantModel(string $model): void
    {
        static::$models['ticket_participant'] = $model;
    }

    public static function useTicketActivityModel(string $model): void
    {
        static::$models['ticket_activity'] = $model;
    }

    public static function useSlaPolicyModel(string $model): void
    {
        static::$models['sla_policy'] = $model;
    }

    public static function useSlaClockModel(string $model): void
    {
        static::$models['sla_clock'] = $model;
    }

    public static function useBusinessHoursModel(string $model): void
    {
        static::$models['business_hours'] = $model;
    }

    public static function useHolidayModel(string $model): void
    {
        static::$models['holiday'] = $model;
    }

    public static function useTeamModel(string $model): void
    {
        static::$models['team'] = $model;
    }

    public static function useTeamMemberModel(string $model): void
    {
        static::$models['team_member'] = $model;
    }

    public static function useRoutingRuleModel(string $model): void
    {
        static::$models['routing_rule'] = $model;
    }

    public static function useSatisfactionRatingModel(string $model): void
    {
        static::$models['satisfaction_rating'] = $model;
    }

    public static function useAutomationRuleModel(string $model): void
    {
        static::$models['automation_rule'] = $model;
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
