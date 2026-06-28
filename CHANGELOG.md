# Changelog

All notable changes to `selli/ticketing` will be documented in this file.

## Unreleased

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
