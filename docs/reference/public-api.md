---
title: "Public API"
description: "Exactly which classes, contracts, events, config keys, tables and HTTP routes are covered by semantic versioning."
type: reference
---

# Public API

`selli/ticketing` follows [semantic versioning](/reference/versioning). This page defines **what "the API"
means**: everything listed here keeps working across every `1.x` release; everything marked `@internal` in
the source may change in any release.

::: callout info "In plain words"
If you only use what is on this page — the facade, the actions, the contracts, the events, the models and
their tables, the config keys and the HTTP routes — a `composer update` within `1.x` will never break your
app. If you reach into a class whose docblock says `@internal` (a listener, an SLA calculator, a controller),
you are on your own.
:::

## PHP surface

| Area | Namespace / class | What is stable |
| --- | --- | --- |
| Facade | `Selli\Ticketing\Facades\Ticketing` | Every `@method` in its docblock (`open`, `for`, `tickets`, `postMessage`, `transition`, `assign`, …) |
| Manager | `Selli\Ticketing\Support\Ticketing` | The same methods, plus the `use*Model()` / `*Model()` model-binding statics, `useUlids()`, `make()` |
| Fluent helpers | `Support\PendingTicket`, `Query\TicketQuery` | Public methods |
| Actions | `Selli\Ticketing\Actions\*` | The `handle()` signature of each action |
| Data objects | `Selli\Ticketing\Data\*` | Constructor arguments (named) and public properties |
| Contracts | `Selli\Ticketing\Contracts\*` | Every interface. New methods are only added in a major release |
| Events | `Selli\Ticketing\Events\*` | Class names and public properties |
| Enums | `Selli\Ticketing\Enums\*` | Cases and their backing values |
| Exceptions | `Selli\Ticketing\Exceptions\*` | Class names and hierarchy (all extend `TicketingException`) |
| Models | `Selli\Ticketing\Models\*` | Relations, scopes, documented helper methods and `@property` attributes |
| Traits | `Concerns\HasTickets`, `Concerns\HasCustomFields`, `Concerns\BelongsToTenant`, `Models\Concerns\ConfiguresTicketingTable` | Public methods |
| Tenancy | `Tenancy\TenantContext`, `Tenancy\DefaultTenantResolver` | `current()`, `forTenant()`, `enabled()`, `column()`, `allowsShared()` |
| Default implementations | `Mail\ConfigInboundMailRouter`, `Mail\NullInboundRequesterResolver`, `Mail\ImapMailbox`, `Collaboration\NullMentionResolver`, `Notifications\ConfigNotificationPreferences`, `Broadcasting\DefaultChannelAuthorizer`, `Policies\TicketPolicy`, `Workflow\ConfigWorkflowDriver`, `Workflow\Guards\RequireResolutionNote` | Behaviour as documented; safe to extend |
| Extension points | `Routing\AssignmentManager::extend()`, `Workflow\WorkflowManager::extend()`, `Mail\MailboxManager::extend()` | Signatures |
| Value objects | `Mail\InboundEmail`, `Mail\MailRoute`, `Workflow\Transition`, `Workflow\TransitionContext` | Constructor and public properties |
| Helpers | `Support\AuditLogger::record()`, `Support\WebhookSigner::sign()` / `verify()`, `Broadcasting\Channels` | Signatures |
| Migrations helper | `Database\Migrations\HasTicketingSchema` | Every method — your published migrations call it |
| Reporting | `Reporting\Metrics` (`Ticketing::metrics()`), `Reporting\CsvExport` | Public methods and the shape of the arrays they return (keys are only added) |
| MCP | `Mcp\TicketingServer`, `TicketingServer::TOOLS`, the tool classes in `Mcp\Tools` | Tool names and arguments (arguments are only added) |

Everything else is `@internal`: listeners and subscribers, the automation engine internals, the SLA
calculators, routing strategies, the HTTP controllers/requests/resources (the **routes and JSON** are public,
see below), jobs, console command classes (the **commands** are public), GDPR internals, token helpers,
`Database\SqlDialect` and the model factories.

## Database

- **Tables and columns** created by the package migrations are public: you may query them, index them and
  reference them from your own tables. Column types only change in a major release.
- New features add **new, additive migrations**; a minor release never edits a published migration. After
  upgrading, publish and run the new ones (`php artisan vendor:publish --tag=ticketing-migrations` then
  `php artisan migrate`).
- Table names and the tenant column are configuration (`ticketing.tables.*`, `ticketing.tenancy.column`).

## Configuration

Every key in `config/ticketing.php` is public. A minor release may **add** keys (always with a default that
keeps the previous behaviour) but never renames or removes one.

## Events and their payloads

Event class names and their public properties are stable. The automation trigger keys (`ticket.opened`,
`message.posted`, …), the notification preference keys (`ticket.assigned`, `sla.breached`, …), the audit
event names (`ticket.transitioned`, …) and the broadcast channel names are stable strings.

## HTTP

The REST API under `{prefix}/v1` is versioned by its URL: within `v1` endpoints, request parameters and
JSON response fields are only ever **added**. A breaking change ships as `v2`, mounted side by side.

## Console

The `ticketing:*` commands and their documented options are stable.
