---
title: "Selli Ticketing"
description: "The agnostic ticketing engine for Laravel — headless, multi-tenant, attach tickets to anything."
type: concept
---

# Selli Ticketing

**The agnostic ticketing engine for Laravel.** Attach tickets to *anything* — your own models, your own UI,
your own tenant — without inheriting an opinionated helpdesk product. `selli/ticketing` is **headless** and
**multi-tenant by default**: it ships the domain (tickets, a config-driven workflow, SLA, routing,
collaboration, CSAT, automation) and the channels (REST API, email-to-ticket, realtime broadcasting), and
leaves the UI, the user model and the product decisions to you.

::: callout tip "New here?"
Start with **[Installation](/getting-started/installation)** → **[Quick Start](/getting-started/quick-start)**,
then read **[Core concepts](/getting-started/concepts)** for the mental model.
:::

## Why headless

Turnkey helpdesks (Faveo, FreeScout, …) are finished products: great if you want to *install a helpdesk*,
the wrong tool if you want to *embed ticketing logic inside your app* with your models, your domain and your
UI. Selli Ticketing is the latter — the engine, not the application.

- **Attach to your models.** A ticket's *subject* is a polymorphic relation: `Ticketing::for($order)->open(...)`.
  Its *requester* and *assignee* are your models implementing two small contracts.
- **Multi-tenant as a security boundary.** A global tenant scope fails closed — no query escapes its tenant
  unless you explicitly ask. See [Multi-tenancy](/concepts/tenancy).
- **Minimal dependencies.** Depends on `illuminate/contracts`, not the full framework. Heavy integrations
  (email inbound, state machines, third-party multitenancy) are optional bridges behind interfaces.
- **Three-layer API.** A `Ticketing` facade for the common path, Action classes for fine control and testing,
  and events for extension.

## What's in the box

| Area | Highlights |
| --- | --- |
| **Domain** | Config-driven [workflow](/concepts/workflow), [SLA + escalation](/concepts/sla), [routing & assignment](/concepts/routing), [collaboration](/concepts/collaboration) (attachments, mentions, canned responses, macros, merge/split, tags) |
| **Feedback** | [CSAT](/concepts/csat) with signed rating links, a data-driven [automation](/concepts/automation) rule engine, outbound webhooks |
| **Channels** | [REST API](/channels/rest-api), [email-to-ticket](/channels/email), [broadcasting](/channels/broadcasting) over Reverb |
| **Security** | [Authorization policies](/security/authorization), an immutable [audit trail](/security/audit-trail), [GDPR](/security/gdpr) anonymisation/export/retention |

## Requirements

- PHP 8.3+
- Laravel 12 or 13
