<?php

declare(strict_types=1);

use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketActivity;
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
        'max_size_kb' => 25600,
        'allowed_mimes' => [],
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
