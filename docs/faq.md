---
title: "FAQ"
description: "Short answers to the questions teams ask before and after adopting selli/ticketing."
type: reference
---

# FAQ

## Product

**Is this a help-desk application?**
No. It is the engine: models, workflow, rules, channels and APIs. You build the screens (Filament,
Inertia, Livewire, a mobile app…) on the facade, the actions or the REST API. The only front-end piece it
ships is a dependency-free [widget client](/channels/widget#client) without UI.

**Can a ticket be about nothing in particular?**
Yes. The subject (`Ticketing::for($order)->open()`) is optional; a free-standing request is a ticket too.

**Can customers without an account open tickets?**
Yes: email senders and widget visitors become [contacts](/concepts/contacts) — per tenant, de-duplicated on
their email, notifiable, erasable.

**Do I have to use multi-tenancy?**
No. Set `ticketing.tenancy.enabled` to `false` for a single-tenant app; the column stays (null) so you can
turn it on later.

## AI

**Is AI required?**
No. It is off unless `ticketing.ai.enabled` is `true`, and then off for every type and tenant until you
choose `auto` or `draft` for them.

**Which providers are supported?**
Any: the AI talks to a small `AiDriver` contract. The package ships `null` and `fake` drivers, the
`rag-engine` driver of the [RAG bridge](/integrations/rag-engine), and you can register your own in a few
lines — for instance on `laravel/ai`:

```php
Ticketing::ai()->extend('laravel-ai', fn ($app, array $config) => new class implements AiDriver {
    public function answer(AiRequest $request): AiAnswer { /* retrieve with Ticketing::knowledge()->search(…), prompt, return sources */ }
    public function classify(AiRequest $request): AiClassification { /* … */ }
    public function summarize(AiRequest $request): AiSummary { /* … */ }
});
```

**Does the AI see internal notes or internal articles?**
No. It receives the public conversation only, and searches the knowledge base for the customer's audience;
with the RAG bridge every hit is re-checked in the database before it reaches the prompt.

**How do I stop the AI on a ticket?**
An agent's public reply stops it for good, or `Ticketing::takeOver($ticket, $agent)` /
`POST /tickets/{id}/takeover`. Per type or tenant, set the mode to `off`.

**How do I know what it costs?**
Every turn records driver, model, tokens, cost and latency (`ticketing_ai_turns`) and emits
`AiUsageRecorded`; `Ticketing::metrics()->ai()` sums the cost per currency.

## Operations

**Do I need a queue worker?**
Recommended: notifications, webhooks, AI turns and RAG indexing run on the queue (after commit). With the
`sync` driver everything still works, inline.

**What must be scheduled?**
`ticketing:escalate` every minute (SLA), `ticketing:prune` daily (retention), and — only with IMAP —
`ticketing:mail:poll` every minute without overlapping.

**Which databases?**
MySQL/MariaDB, PostgreSQL and SQLite are tested on every change; the SQL avoids vendor-specific
constructs, SQL Server included. With ULID ids on MySQL, set `Schema::defaultStringLength(191)` (see
[Installation](/getting-started/installation)).

**How fast is it with a lot of tickets?**
Listing, filtering, routing, the SLA sweep and the KPIs run a bounded number of queries; the suite checks
them on 10 000 tickets on every database.

## Upgrades and support

**Will an update break my app?**
Not within `1.x`: see the [public API](/reference/public-api) and [versioning](/reference/versioning).
Read `UPGRADE.md` for behaviour changes between versions; new migrations are only ever added.

**How do I report a security issue?**
Privately, through GitHub's *Report a vulnerability* — see `SECURITY.md`.
