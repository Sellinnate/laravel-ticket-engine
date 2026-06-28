# Changelog

All notable changes to `selli/ticketing` will be documented in this file.

## Unreleased

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
