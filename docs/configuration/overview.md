---
title: "Configuration overview"
description: "A tour of config/ticketing.php, validated at boot."
type: reference
---

# Configuration overview

All configuration lives in `config/ticketing.php` (publish it with `ticketing:install`). It is
**auto-documented** with comments and **validated at boot** — a workflow that references a missing state
fails fast.

## Sections

| Key | What it controls |
| --- | --- |
| `tenancy` | the resolver, column, shared-row policy and fail-closed writes — see [Multi-tenancy](/concepts/tenancy) |
| `models` / `tables` | swap any model or table name (the GDPR/erasure paths honour these) |
| `ids` | auto-increment (default) or `ulid` primary keys |
| `workflow` | per-type state machines + boot validation — see [Types & workflow](/concepts/workflow) |
| `types` | seedable ticket types (key → name, workflow, default priority) |
| `sla` | targets and the default threshold — see [SLA](/concepts/sla) |
| `routing` | routing engine + default strategy — see [Routing](/concepts/routing) |
| `collaboration` | attachments (disk, mime/size), mentions resolver |
| `csat` | scale, auto-request, token secret — see [CSAT](/concepts/csat) |
| `automation` / `webhooks` | rule engine + SSRF-guarded outbound — see [Automation](/concepts/automation) |
| `notifications` | channels, preferences, throttle, Slack — see [Notifications](/concepts/notifications) |
| `api` | the opt-in [REST API](/channels/rest-api) |
| `mail` | inbound routing + outbound threading — see [Email-to-ticket](/channels/email) |
| `broadcasting` | realtime channels — see [Broadcasting](/channels/broadcasting) |
| `authorization` | policy registration — see [Authorization](/security/authorization) |
| `gdpr` | anonymisation label + retention rules — see [GDPR](/security/gdpr) |
| `queue` | the connection/queue for side effects (notifications, webhooks, sweeps) |

## Design notes

- **Opt-in by default.** Channels (API, email, broadcasting) and notification channels are off until you turn
  them on, so an upgrade never changes runtime behaviour.
- **Fail closed.** Unknown config (a missing workflow state, an unknown notification channel) raises
  `InvalidConfigurationException` rather than guessing.
- **Side effects on the queue.** Notifications, email, escalation and webhooks run on the queue, never inline
  in the request.
