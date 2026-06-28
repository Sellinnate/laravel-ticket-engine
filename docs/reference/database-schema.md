---
title: "Database schema"
description: "The package's tables and what they hold."
type: reference
---

# Database schema

Every table name is configurable under `ticketing.tables` (and prefixable). Every model can be swapped via
`ticketing.models`. Primary keys are auto-increment by default, or ULIDs with `ticketing.ids.type = 'ulid'`.

| Table | Holds |
| --- | --- |
| `tickets` | the core record (reference, status, priority, category, subject/assignee morphs, SLA timestamps). Soft-deleted. |
| `ticket_sequences` | the atomic per-(tenant, type, year) counter behind each human reference |
| `ticket_messages` | replies and notes (`visibility`, `body_format`, `source`, `meta`). Soft-deleted. |
| `ticket_participants` | who's on a ticket (requester, assignee, watcher, collaborator, cc) |
| `ticket_activities` | the **immutable** audit trail |
| `ticket_attachments` | polymorphic attachments on tickets and messages |
| `ticket_links` | extra subjects linked to a ticket |
| `ticket_types` | per-tenant types (key → workflow, default priority) |
| `sla_policies` / `sla_clocks` | SLA targets and per-ticket clocks |
| `business_hours` / `holidays` | calendars for SLA time accounting |
| `teams` / `team_members` | agent teams |
| `routing_rules` | data-driven routing |
| `canned_responses` / `macros` | reusable replies and bundled action sets |
| `satisfaction_ratings` | CSAT |
| `automation_rules` | the automation rule engine |
| `tags` / `taggables` | per-tenant tags |
| `ticketing_inbound_emails` | the email idempotency ledger (unique Message-ID) |

## Tenant column

Every domain table carries the configured tenant column (default `tenant_id`, nullable for shared rows). The
global [tenant scope](/concepts/tenancy) filters reads to the current tenant and fails closed when none is
resolved.
