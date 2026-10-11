---
title: "Selli Ticketing"
description: "The headless, multi-tenant help-desk engine for Laravel — tickets on anything, with workflow, SLA, channels, AI, knowledge base and KPIs."
type: concept
---

# Selli Ticketing

![Selli Ticketing — the headless, multi-tenant help-desk engine for Laravel](/assets/banner.png)

**The headless, multi-tenant help-desk engine for Laravel.** Tickets about anything in your app — an order, a
device, a contract, or nothing at all — with the workflow, SLA, routing, channels, AI and reporting a support
team needs, and none of the UI or product decisions of a finished help desk.

::: callout tip "New here?"
Start with **[Installation](/getting-started/installation)** → **[Quick Start](/getting-started/quick-start)**,
then read **[Core concepts](/getting-started/concepts)** for the mental model. Building an AI-assisted help
desk? Follow **[An AI help desk with RAG](/guides/ai-help-desk)**.
:::

## Why headless

Turnkey help desks are finished products: great if you want to *install a help desk*, the wrong tool if you
want to *embed ticketing inside your app* with your models, your domain and your UI. Selli Ticketing is the
engine, not the application.

- **Attach to your models.** A ticket's subject is polymorphic (`Ticketing::for($order)->open(...)`); its
  requester and assignee are your models implementing two small contracts — or contacts for people without
  an account.
- **Multi-tenant as a security boundary.** A global tenant scope fails closed; cross-tenant links are refused.
  See [Multi-tenancy](/concepts/tenancy) and the [security model](/security/model).
- **Three-layer API.** A facade for the common path, Action classes for fine control and testing, events
  (after commit) for extension. A [stable public API](/reference/public-api) under semantic versioning.
- **Minimal dependencies.** Heavy integrations (IMAP, MCP, RAG, Reverb) are optional, behind interfaces.

## What's in the box

| Area | Highlights |
| --- | --- |
| **Domain** | Config-driven [workflow](/concepts/workflow), [SLA + escalation](/concepts/sla), [routing & assignment](/concepts/routing), [collaboration](/concepts/collaboration), [listing & filtering](/concepts/querying) |
| **People** | Your models as requesters and agents, [contacts](/concepts/contacts), reopen on requester reply |
| **Channels** | [REST API](/channels/rest-api), [email](/channels/email) (webhook or IMAP), [chat widget](/channels/widget) with a reference JS client, [broadcasting](/channels/broadcasting) |
| **AI** | Provider-agnostic [AI assistance](/concepts/ai): auto / draft / off, escalation rules, human takeover, usage and cost |
| **Knowledge** | [Knowledge base](/concepts/knowledge-base) with audiences, versions and locales; semantic search with the [RAG bridge](/integrations/rag-engine) |
| **Feedback & automation** | [CSAT](/concepts/csat), [automation](/concepts/automation) rules and webhooks, [notifications](/concepts/notifications) |
| **Insight** | [KPIs and CSV exports](/concepts/reporting) |
| **Integrations** | [MCP tools](/integrations/mcp) for AI agents, [RAG Engine bridge](/integrations/rag-engine) |
| **Security** | [Policies](/security/authorization), immutable [audit trail](/security/audit-trail), [GDPR](/security/gdpr) erasure / export / retention |

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13
- MySQL/MariaDB, PostgreSQL or SQLite (tested on every change)
