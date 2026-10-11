# Changelog

All notable changes to `selli/ticketing` will be documented in this file.

## v1.0.0 — first stable release - 2026-10-11

First stable release: the public API is now covered by semantic versioning (see
[Public API](https://laravel-ticketing.selli.io/reference/public-api) and `UPGRADE.md`).

### Added

- **Contacts** — external requesters without an account (`Ticketing::contacts()`), de-duplicated per tenant
  and email; inbound email senders become contacts.
- **Knowledge base** — categories, articles and FAQs with versions, locales, audiences (public, customer,
  internal, per client), feedback, ticket links, portable keyword search behind `KnowledgeSearch`; REST
  endpoints and `KbArticlePolicy`.
- **AI layer** — provider-agnostic `AiDriver` (`null`, `fake`, `rag-engine`, custom), modes auto / draft /
  off per tenant and type, classification, escalation rules, `EscalateTicket` (routing → escalation team →
  team leads), human takeover, one idempotent AI turn per message with usage and cost; events `AiReplied`,
  `AiDraftCreated`, `TicketEscalated`, `HumanTookOver`, `AiTurnFailed`, `AiUsageRecorded`.
- **Chat widget channel** — public JSON API with per-tenant keys, origin allow-list, HMAC-signed visitor
  identity, hashed bearer tokens, rate limits, honeypot, `HumanVerifier`; conversations become tickets with
  their transcript; a dependency-free reference JS client (`vendor:publish --tag=ticketing-widget`).
- **Email channel, completed** — indexed Message-ID threading, signed outbound Message-IDs, subject
  reference fallback, quote and signature stripping, Cc participants, bounce / no-reply / own-mail
  anti-loop, threading headers and full replies in notifications, `Auto-Submitted` on AI answers;
  `InboundMailbox` contract, IMAP implementation (`directorytree/imapengine`) and `ticketing:mail:poll`.
- **Reporting** — `Ticketing::metrics()`: volume, backlog, first response and resolution (mean and median),
  reopen rate, SLA compliance, AI share and cost, CSAT, knowledge gaps, breakdowns; streamed CSV exports;
  `GET /reports/*` endpoints behind `viewReports`.
- **MCP tools** (optional, `laravel/mcp`) — `Mcp\TicketingServer` with policy-checked help-desk tools.
- **RAG Engine bridge** (optional, `sellinnate/rag-engine`) — indexing of articles, canned responses and
  resolved tickets (question + final answer, personal data removed), hybrid fail-closed semantic search
  re-verified in the database, `rag-engine` AI driver with citations, similar tickets, GDPR purges,
  `ticketing:rag:index` (backfill and reconciliation, per-kind switches).
- `TicketAnonymized` event when retention anonymises a ticket; durable erasure markers in the audit trail
  (`requester.anonymized`, `ticket.anonymized`, `ticket.erasure_inherited` through merge, split and
  retention deletes) that keep erased conversations out of later copies such as a search index.
- `TicketQuery` (`Ticketing::tickets()`): listing filters, sort, offset/cursor pagination, used by the REST
  index; `channel` on tickets; reopen on requester reply; `SqlDialect` for portable SQL.
- Documentation site (docmd) with guides, references, security and FAQ; Cloudflare Worker config.

### Changed

- See `UPGRADE.md` → *Behaviour changes*: contacts for inbound email, reopen on reply, REST string ids and
  list filters, inbound email storage and threading, reply notification content, tenant checks on
  requesters and participants.
- PHPStan level 8 (was 6); non-public classes marked `@internal`.

### Fixed

- Many concurrency, tenancy and idempotency issues found by the v1 adversarial review
  (`docs/technical/hardening-v1.md`), each with a regression test.

### Removed

- Claims of integrations that never existed (a Spatie state-class workflow bridge, an auto-wired
  laravel-mailbox bridge, tenancy/permission bridges); tenancy bridges are documented recipes.

Documentation: https://laravel-ticketing.selli.io

```bash
composer require selli/ticketing:^1.0

```
## 1.0.0 - 2026-10-10

First stable release: the public API is now covered by semantic versioning (see
[Public API](https://laravel-ticketing.selli.io/reference/public-api) and `UPGRADE.md`).

### Added

- **Contacts** — external requesters without an account (`Ticketing::contacts()`), de-duplicated per tenant
  and email; inbound email senders become contacts.
- **Knowledge base** — categories, articles and FAQs with versions, locales, audiences (public, customer,
  internal, per client), feedback, ticket links, portable keyword search behind `KnowledgeSearch`; REST
  endpoints and `KbArticlePolicy`.
- **AI layer** — provider-agnostic `AiDriver` (`null`, `fake`, `rag-engine`, custom), modes auto / draft /
  off per tenant and type, classification, escalation rules, `EscalateTicket` (routing → escalation team →
  team leads), human takeover, one idempotent AI turn per message with usage and cost; events `AiReplied`,
  `AiDraftCreated`, `TicketEscalated`, `HumanTookOver`, `AiTurnFailed`, `AiUsageRecorded`.
- **Chat widget channel** — public JSON API with per-tenant keys, origin allow-list, HMAC-signed visitor
  identity, hashed bearer tokens, rate limits, honeypot, `HumanVerifier`; conversations become tickets with
  their transcript; a dependency-free reference JS client (`vendor:publish --tag=ticketing-widget`).
- **Email channel, completed** — indexed Message-ID threading, signed outbound Message-IDs, subject
  reference fallback, quote and signature stripping, Cc participants, bounce / no-reply / own-mail
  anti-loop, threading headers and full replies in notifications, `Auto-Submitted` on AI answers;
  `InboundMailbox` contract, IMAP implementation (`directorytree/imapengine`) and `ticketing:mail:poll`.
- **Reporting** — `Ticketing::metrics()`: volume, backlog, first response and resolution (mean and median),
  reopen rate, SLA compliance, AI share and cost, CSAT, knowledge gaps, breakdowns; streamed CSV exports;
  `GET /reports/*` endpoints behind `viewReports`.
- **MCP tools** (optional, `laravel/mcp`) — `Mcp\TicketingServer` with policy-checked help-desk tools.
- **RAG Engine bridge** (optional, `sellinnate/rag-engine`) — indexing of articles, canned responses and
  resolved tickets (question + final answer, personal data removed), hybrid fail-closed semantic search
  re-verified in the database, `rag-engine` AI driver with citations, similar tickets, GDPR purges,
  `ticketing:rag:index` (backfill and reconciliation, per-kind switches).
- `TicketAnonymized` event when retention anonymises a ticket; durable erasure markers in the audit trail
  (`requester.anonymized`, `ticket.anonymized`, `ticket.erasure_inherited` through merge, split and
  retention deletes) that keep erased conversations out of later copies such as a search index.
- `TicketQuery` (`Ticketing::tickets()`): listing filters, sort, offset/cursor pagination, used by the REST
  index; `channel` on tickets; reopen on requester reply; `SqlDialect` for portable SQL.
- Documentation site (docmd) with guides, references, security and FAQ; Cloudflare Worker config.

### Changed

- See `UPGRADE.md` → *Behaviour changes*: contacts for inbound email, reopen on reply, REST string ids and
  list filters, inbound email storage and threading, reply notification content, tenant checks on
  requesters and participants.
- PHPStan level 8 (was 6); non-public classes marked `@internal`.

### Fixed

- Many concurrency, tenancy and idempotency issues found by the v1 adversarial review
  (`docs/technical/hardening-v1.md`), each with a regression test.

### Removed

- Claims of integrations that never existed (a Spatie state-class workflow bridge, an auto-wired
  laravel-mailbox bridge, tenancy/permission bridges); tenancy bridges are documented recipes.

## Pre-release development (`dev-main`)

### Added — Collaboration

- Attachments: `TicketAttachment` (poly on ticket/message) + `AddAttachment`
  action with size/mime validation, checksum and a temporary download URL.
- Tags: `Tag` + taggables pivot; `for($ticket)->tag()/untag()` (per-tenant).
- Canned responses: `CannedResponse` with `{{placeholder}}` rendering.
- Macros: `Macro` + `ApplyMacro` (reply + assignment + transition + tags in one
  transaction).
- Merge/split: `MergeTickets` and `SplitTicket` actions + `TicketMerged`/
  `TicketSplit` events; `ticket_links` for related subjects.
- Mentions: `MentionParser` + `MentionResolver` contract (host-bound), wired so
  an `@mention` adds the actor as a collaborator (`ParticipantAdded`).

### Added — Routing & assignment

- Models: `Team`, `TeamMember` (skills + round-robin state), `RoutingRule`.
- `AssignmentManager` with interchangeable strategies (`manual`, `round-robin`,
  `least-busy`, `skill-based`) and `extend()` for custom strategies; optional
  `ReportsAvailability` contract so routing skips unavailable agents.
- `RoutingEngine`: ordered, data-driven rules (conditions → team/assignee/
  strategy) evaluated on open; rich condition operators.
- `AssignTicket` action (row-locked) + `TicketAssigned` / `ParticipantAdded`
  events; `Ticketing::assign()`, `for($ticket)->assignTo()` / `assignToTeam()`.

### Added — SLA, business hours & escalation

- `BusinessHours` value object: deadline/elapsed math over working hours with
  weekly schedules, timezones and holidays (24/7 supported).
- Models: `SlaPolicy` (type+priority matching with catch-all fallback),
  `BusinessHours` + `Holiday` calendars, `SlaClock` (per ticket/target runtime
  timer with pause support).
- `SlaManager`: starts first-response/resolution clocks on open, completes the
  first-response clock on the first agent reply, pauses/resumes around customer-
  wait states (recomputing the deadline), completes/restarts the resolution
  clock on resolve/reopen, and sweeps for thresholds & breaches per tenant.
- Resolvers: `SlaPolicyResolver` (specificity ranking), `CalendarResolver`
  (model → working calendar + holidays).
- Events `SlaThresholdReached`, `SlaBreached`; `SlaSubscriber` wires the engine
  to the domain events.
- Commands `ticketing:escalate` (scheduleable sweep) and
  `ticketing:recalculate-sla`.

### Added — Workflow engine

- `WorkflowDriver` contract + `WorkflowManager` (driver resolution, `extend()`
  for custom drivers such as a future spatie/laravel-model-states bridge).
- `ConfigWorkflowDriver`: config-declared states, transitions, terminal states
  and system-semantic mapping (open/closed/paused).
- `Transition`/`TransitionContext` value objects and `TransitionGuard` contract,
  with a `RequireResolutionNote` example guard.
- `TransitionTicket` action: validates the transition, runs guards, updates
  derived lifecycle timestamps (resolved_at/closed_at), counts reopens, writes
  the audit entry and emits `StateTransitioned`, `TicketResolved`,
  `TicketClosed`, `TicketReopened`.
- `Ticketing::transition()` / `Ticketing::for($ticket)->transition(...)`.

### Added — Foundation

- Package scaffolding for `selli/ticketing` (namespace `Selli\Ticketing`).
- Agnostic domain contracts: `Ticketable`, `CanRequestTickets`, `CanActOnTickets`,
  `TenantResolver`, `TenantScoped`.
- Native multi-tenancy: `BelongsToTenant` trait + global `TenantScope` that fails
  closed (no tenant resolved ⇒ only shared rows are visible), `TenantContext` with
  explicit per-tenant overrides for CLI/queues, and a default auth-based resolver.
- Core models with configurable table names and ULID/auto IDs: `Ticket`,
  `TicketType`, `TicketMessage`, `TicketParticipant`, append-only `TicketActivity`.
- Concerns: `HasTickets`, `HasCustomFields`.
- Typed enums: `Priority`, `MessageVisibility`, `ParticipantRole`, `MessageSource`,
  `BodyFormat`, `SlaTarget`.
- `Ticketing` facade/manager with `open()`, `for()->open()`, `for()->postMessage()`,
  overridable model bindings (`useTicketModel`, …), and ULID toggle.
- Actions: `OpenTicket` (unique per-tenant references with retry), `PostMessage`
  (first-response stamping, public/internal visibility).
- Domain events: `TicketOpened`, `MessagePosted`.
- Immutable audit trail via `AuditLogger`.
- Boot-time configuration validation (`ConfigValidator`) — fail-fast on bad workflows.
- Publishable migrations and a fully documented `config/ticketing.php`.
- Pest test suite (95%+ coverage), Pest arch rules, PHPStan level 6, Laravel Pint.
