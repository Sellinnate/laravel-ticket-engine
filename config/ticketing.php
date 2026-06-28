<?php

declare(strict_types=1);

use Selli\Ticketing\Collaboration\NullMentionResolver;
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
use Selli\Ticketing\Tenancy\DefaultTenantResolver;
use Selli\Ticketing\Workflow\Guards\RequireResolutionNote;

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy
    |--------------------------------------------------------------------------
    |
    | Tenancy is on by default. Every package table carries a tenant column and
    | every query is scoped to the current tenant. Set "enabled" to false to run
    | the engine single-tenant (the tenant column then stays null everywhere).
    |
    | "column" is the foreign-key column name used across all package tables —
    | change it once here (e.g. to "company_id" or "team_id") and it applies
    | universally. "allow_shared" makes null-tenant rows visible to every tenant
    | (handy for system ticket types or national holidays).
    |
    */
    'tenancy' => [
        'enabled' => true,
        'column' => 'tenant_id',

        // Database type of the tenant column across package tables:
        // 'unsignedBigInteger' | 'uuid' | 'ulid' | 'string'.
        'column_type' => 'unsignedBigInteger',

        'allow_shared' => true,

        // Fail closed on writes: when true, persisting a tenant-scoped model
        // while tenancy is enabled but no tenant is resolved throws instead of
        // silently creating a shared (null-tenant) row. Recommended for
        // multi-tenant production. An explicit null tenant (intentional shared
        // record) is always allowed. Default false to keep seeders/factories
        // and single-tenant setups ergonomic.
        'require_tenant_for_writes' => false,

        // Bind your own resolver, or one of the optional bridges:
        //   \Selli\Ticketing\Tenancy\DefaultTenantResolver::class (from auth user)
        'resolver' => DefaultTenantResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Primary keys
    |--------------------------------------------------------------------------
    |
    | Auto-increment by default. Enable ULIDs when you expose IDs in URLs/emails
    | or distribute writes across nodes. This must be decided before migrating.
    |
    */
    'ids' => [
        'type' => 'auto', // 'auto' | 'ulid'
    ],

    /*
    |--------------------------------------------------------------------------
    | Database table names
    |--------------------------------------------------------------------------
    |
    | Prefix all table names to avoid collisions with the host schema.
    |
    */
    'tables' => [
        'prefix' => '',
        'tickets' => 'tickets',
        'ticket_sequences' => 'ticket_sequences',
        'ticket_messages' => 'ticket_messages',
        'ticket_participants' => 'ticket_participants',
        'ticket_activities' => 'ticket_activities',
        'ticket_attachments' => 'ticket_attachments',
        'ticket_links' => 'ticket_links',
        'ticket_types' => 'ticket_types',
        'sla_policies' => 'sla_policies',
        'sla_clocks' => 'sla_clocks',
        'business_hours' => 'business_hours',
        'holidays' => 'holidays',
        'teams' => 'teams',
        'team_members' => 'team_members',
        'routing_rules' => 'routing_rules',
        'canned_responses' => 'canned_responses',
        'macros' => 'macros',
        'satisfaction_ratings' => 'satisfaction_ratings',
        'automation_rules' => 'automation_rules',
        'tags' => 'tags',
        'taggables' => 'taggables',
    ],

    /*
    |--------------------------------------------------------------------------
    | Eloquent model bindings
    |--------------------------------------------------------------------------
    |
    | Override any model with your own subclass via Ticketing::useTicketModel()
    | or by editing the mapping below.
    |
    */
    'models' => [
        'ticket' => Ticket::class,
        'ticket_type' => TicketType::class,
        'ticket_message' => TicketMessage::class,
        'ticket_participant' => TicketParticipant::class,
        'ticket_activity' => TicketActivity::class,
        'sla_policy' => SlaPolicy::class,
        'sla_clock' => SlaClock::class,
        'business_hours' => BusinessHours::class,
        'holiday' => Holiday::class,
        'team' => Team::class,
        'team_member' => TeamMember::class,
        'routing_rule' => RoutingRule::class,
        'ticket_attachment' => TicketAttachment::class,
        'canned_response' => CannedResponse::class,
        'macro' => Macro::class,
        'tag' => Tag::class,
        'ticket_link' => TicketLink::class,
        'satisfaction_rating' => SatisfactionRating::class,
        'automation_rule' => AutomationRule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing & assignment
    |--------------------------------------------------------------------------
    |
    | "default_strategy" picks the agent within a team when a routing rule (or an
    | explicit team assignment) does not specify one. Available out of the box:
    | manual, round-robin, least-busy, skill-based. Set "enabled" to false to
    | disable automatic routing on open.
    |
    */
    'routing' => [
        'enabled' => true,
        'default_strategy' => 'manual',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reference codes
    |--------------------------------------------------------------------------
    |
    | Human-friendly per-tenant ticket references, e.g. "INC-2026-00042". The
    | {type} token is the uppercased ticket type key, {year} the current year,
    | {seq} a zero-padded per-tenant sequence.
    |
    */
    'reference' => [
        'format' => '{type}-{year}-{seq}',
        'sequence_padding' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow engine
    |--------------------------------------------------------------------------
    |
    | "driver" selects how states & transitions are resolved:
    |   - config: declarative, defined below per workflow key (default)
    |   - state-class: bridge to spatie/laravel-model-states (suggested dep)
    |
    | Each workflow declares its states, transitions and the mapping of custom
    | states onto the system semantics the core understands (open/closed/paused
    | /terminal). Config is validated fail-fast at boot.
    |
    */
    'workflow' => [
        'driver' => 'config',

        // Validate the workflow configuration at boot (fail-fast on typos).
        'validate_on_boot' => true,

        'workflows' => [
            'default' => [
                'initial' => 'open',
                'states' => ['open', 'pending', 'resolved', 'closed'],
                'transitions' => [
                    'start' => ['from' => ['open'], 'to' => 'open'],
                    'wait' => ['from' => ['open'], 'to' => 'pending'],
                    'resume' => ['from' => ['pending'], 'to' => 'open'],
                    'resolve' => ['from' => ['open', 'pending'], 'to' => 'resolved'],
                    'close' => ['from' => ['resolved', 'open', 'pending'], 'to' => 'closed'],
                    'reopen' => ['from' => ['resolved', 'closed'], 'to' => 'open'],
                ],
                'terminal' => ['closed'],
                'semantics' => [
                    'open' => ['open'],
                    'closed' => ['closed', 'resolved'],
                    'paused' => ['pending'],
                ],
            ],

            'incident' => [
                'initial' => 'new',
                'states' => ['new', 'triaged', 'in_progress', 'pending_customer', 'resolved', 'closed'],
                'transitions' => [
                    'triage' => ['from' => ['new'], 'to' => 'triaged'],
                    'start' => ['from' => ['triaged'], 'to' => 'in_progress'],
                    'wait' => ['from' => ['in_progress'], 'to' => 'pending_customer'],
                    'resume' => ['from' => ['pending_customer'], 'to' => 'in_progress'],
                    'resolve' => ['from' => ['in_progress'], 'to' => 'resolved', 'guard' => RequireResolutionNote::class],
                    'close' => ['from' => ['resolved'], 'to' => 'closed'],
                    'reopen' => ['from' => ['resolved', 'closed'], 'to' => 'in_progress'],
                ],
                'terminal' => ['closed'],
                'semantics' => [
                    'open' => ['new', 'triaged', 'in_progress'],
                    'closed' => ['resolved', 'closed'],
                    'paused' => ['pending_customer'],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SLA
    |--------------------------------------------------------------------------
    |
    | The SLA engine starts/pauses/stops response & resolution clocks in reaction
    | to domain events. Schedule `ticketing:escalate` (e.g. every minute) to emit
    | SlaThresholdReached / SlaBreached. SLA policies, business hours and holidays
    | live in the database (seed your own per tenant).
    |
    */
    'sla' => [
        'enabled' => true,
        'default_threshold_percent' => 75,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket types
    |--------------------------------------------------------------------------
    |
    | Default seedable types. Each maps to a workflow key above. Types are also
    | stored per-tenant in the database; these provide sensible bootstrap data.
    |
    */
    'types' => [
        'support' => ['name' => 'Support', 'workflow' => 'default', 'default_priority' => 20],
        'incident' => ['name' => 'Incident', 'workflow' => 'incident', 'default_priority' => 30],
        'request' => ['name' => 'Request', 'workflow' => 'default', 'default_priority' => 20],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subject links
    |--------------------------------------------------------------------------
    |
    | Allow linking additional related subjects to a ticket via ticket_links.
    |
    */
    'subject_links' => [
        'enabled' => true,
        'roles' => ['affects', 'references', 'caused_by'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    */
    'attachments' => [
        'disk' => 'local',

        // The disks an attachment may be stored on. A request-supplied disk is
        // rejected unless it appears here, so a caller that binds the disk from
        // untrusted input cannot redirect a blob onto a public/served disk
        // (e.g. an SVG/HTML upload that would then render inline → stored XSS).
        // The default disk above is always allowed. Empty list = only it.
        'allowed_disks' => ['local'],

        'max_size_kb' => 25600,

        // Allowed MIME types (content-sniffed, not the client header). An empty
        // list accepts any type — set an explicit allow-list in production.
        'allowed_mimes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Collaboration
    |--------------------------------------------------------------------------
    |
    | @mentions in messages add the mentioned actor as a collaborator. Bind your
    | own MentionResolver (resolves a handle to a host model) — the default
    | resolves nobody.
    |
    */
    'collaboration' => [
        'mentions' => [
            'enabled' => true,
            'resolver' => NullMentionResolver::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CSAT (customer satisfaction)
    |--------------------------------------------------------------------------
    |
    | On resolution the package can request a satisfaction rating. It is headless:
    | the CsatRequested event carries a signed token your app embeds in its own
    | rating URL; the submit Action records the rating (one per ticket, re-armed
    | if the ticket is reopened and resolved again).
    |
    | scale: "five_star" (1-5), "thumbs" (0/1) or "nps" (0-10).
    | token.secret: HMAC key for the signed token (defaults to the app key).
    | token.ttl: how long a request link stays valid, in seconds.
    |
    | The token is a bearer credential — serve rating links over HTTPS and keep
    | the TTL as short as your flow allows. submitCsatByToken records one
    | valuation per cycle (a re-clicked link won't overwrite it).
    |
    */
    'csat' => [
        'enabled' => true,
        'auto_request' => true,
        'scale' => 'five_star',
        'token' => [
            'secret' => null,
            'ttl' => 1209600, // 14 days
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Automation (rule engine)
    |--------------------------------------------------------------------------
    |
    | Data-driven rules run on domain events: a trigger (event) + conditions
    | (predicates on the ticket) + actions (transition, assign, tag, reply,
    | priority, apply_macro, webhook). Rules are per-tenant and ordered by
    | priority. "max_depth" bounds re-entrant cascades (a rule action that
    | re-fires a trigger).
    |
    */
    'automation' => [
        'enabled' => true,
        'max_depth' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound webhooks
    |--------------------------------------------------------------------------
    |
    | The "webhook" automation action POSTs a signed payload to an external URL
    | off the request (queued, retried). "secret" is the default HMAC key (a rule
    | may override it); receivers verify the X-Ticketing-Signature header.
    |
    */
    'webhooks' => [
        'secret' => null,
        'timeout' => 5,
        'tries' => 3,

        // SSRF guard: block requests that resolve to private/loopback/link-local
        // addresses. Set an explicit allow-list of hosts to bypass the heuristic
        // (the allow-list is then authoritative).
        'block_private' => true,
        'allowed_hosts' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | The package registers policies for its models. Set "register_policies" to
    | false to register your own. "agents" / "requesters" let you point at the
    | host contracts implemented by your user models.
    |
    */
    'authorization' => [
        'register_policies' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Connection/queue used for side effects (notifications, webhooks, sweeps).
    |
    */
    'queue' => [
        'connection' => null,
        'queue' => null,
    ],
];
