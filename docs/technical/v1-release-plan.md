---
title: "v1.0.0 release plan"
description: "Audit of the pre-1.0 codebase, the gaps to close for a stable release, the features added to make the package the Selli ticketing standard, and the work phases."
---

# v1.0.0 release plan

**Goal:** ship `selli/ticketing` 1.0.0 — the first stable, semver-governed release — as the ticketing
standard for every Selli product (SalesMind, Aurelia and the other back-offices), with an optional
integration with the Selli RAG Engine (`sellinnate/rag-engine`).

This document is the output of a full audit of the code, tests, documentation and CI as of
`f2f677c` (29 June 2026), and the plan the release work follows. Each phase lands as its own pull
request into `feature/v1-stable`; the final pull request into `main` is merged by the maintainer, who
then tags `v1.0.0` (see the [release runbook](/technical/release-runbook)).

## Audit method

- Read every class in `src/`, every migration, the config file, the 49 test files and the 23 docs pages.
- Ran the suite (412 tests, 894 assertions, green on SQLite), PHPStan at level 8 (45 errors at the
  current level 6 baseline) and Pint (clean).
- Compared the claims in `README.md`, `config/ticketing.php`, `composer.json` and the docs with what is
  actually implemented.
- Mapped the requirements of the reference consumer — the *Supporto clienti* module of SalesMind
  (`SupportRequest`, AI chatbot with RAG, embeddable widget, email threading, KPI, MCP) — onto the
  package, to find what a host would still have to build itself.

## Gaps found

### Claims that are not implemented

The release rule is *everything we claim must exist*. The audit found five claims with no code behind
them:

| Claim | Where | Resolution |
| --- | --- | --- |
| "Spatie state-class workflow bridge" (`workflow.driver = state-class`) | README, config comment, `docs/concepts/workflow.md`, `suggest` | Removed. `WorkflowManager::extend()` is the documented extension point; no Selli project needs typed state classes. |
| "A beyondcode/laravel-mailbox bridge is auto-wired" | config comment, `suggest` | Removed. Inbound mail gets a real adapter instead (IMAP polling, below) plus the documented webhook mapping. |
| stancl/tenancy and spatie/laravel-multitenancy bridges | `suggest` | Removed from `suggest`. A `TenantResolver` is a five-line class; the docs ship recipes for both packages instead of untested bridges. |
| spatie/laravel-permission "integration" | `suggest` | Removed. The policy is host-replaceable; the docs show how to delegate to permissions. |
| "Laravel 12 or 13" | `docs/index.md` | `composer.json` allows Laravel 11; CI now tests 11, 12 and 13. |

### Inconsistencies

- `composer.json` `homepage`, the README badges and `dependabot-auto-merge.yml` point at
  `Sellinnate/laravel-ticketing` (a repository that does not exist); the dependabot guard therefore never
  matches. All links move to `Sellinnate/laravel-ticket-engine` and the docs site.
- `Ticketing::use*Model()` setters exist for 14 of the 19 bindable models; `ticket_attachment`,
  `canned_response`, `macro`, `tag` and `ticket_link` can only be overridden through config.
- The `Ticketing` facade docblock misses `tag`, `untag`, `applyMacro`, `merge`, `split`, `requestCsat` and
  `csatToken`, so IDEs and static analysis cannot see them.
- No `support` block in `composer.json`, no `SECURITY.md`, `CONTRIBUTING.md` or `UPGRADE.md`, and the
  CHANGELOG is entirely "Unreleased".
- `.gitattributes` does not export-ignore the docs build tooling (`package.json`, `docmd.config.json`,
  `site/`, `build/`), so the Packagist archive would ship them.

### Public API not defined

Nothing tells a consumer which classes are covered by semver. Listeners, subscribers, the automation
internals, the SLA calculators and the HTTP controllers are public PHP classes a host could depend on by
accident. For 1.0 the public API is defined explicitly (facade, actions, data objects, contracts, events,
enums, exceptions, models and their tables, config keys, HTTP routes and their JSON shapes); everything
else is marked `@internal` and the policy is written down in `docs/reference/public-api.md`,
`docs/reference/versioning.md` and `UPGRADE.md`.

### Static analysis

PHPStan runs at level 6; the RAG Engine runs at level 8. Raising the level surfaces 45 errors, mostly
nullable relations and loosely typed collections in `SplitTicket`, `MergeTickets`, the `Ticketing`
manager and `ReferenceGenerator`. They are fixed in code, not baselined.

### Performance

| Area | Finding | Fix |
| --- | --- | --- |
| Assignment | `least-busy` and `skill-based` run one `COUNT(*)` per team member on every assignment. | One grouped count query per assignment. |
| Notifications | `RecipientResolver` lazy-loads every participant's morph (N+1 per message). | Eager-load the participant morph. |
| Email threading | `In-Reply-To`/`References` are matched with an unindexed JSON path (`meta->message_id`) — a full scan of `ticket_messages`. | A dedicated, indexed `email_message_id` column (additive migration), with the JSON path kept as a fallback for rows written before 1.0. |
| Listing | The API filters only by status, category and priority; ordering by `created_at` has no tenant-leading index; "my tickets" for requesters probes participants without a `(participant_type, participant_id)` index. | A reusable `TicketQuery` filter object (state semantic, status, priority, type, assignee incl. `me`/`unassigned`, team, channel, tag, requester, date range, keyword) with a whitelisted sort, used by the REST API, MCP tools and exports, plus the missing indexes. |
| Reporting | There is no reporting layer yet; KPIs must aggregate in SQL. | All metrics aggregate in the database (portable duration expressions for SQLite, MySQL/MariaDB, PostgreSQL and SQL Server; medians via an ordered `OFFSET` query), with volume tests on 10 000 tickets. |

New indexes ship in **new, additive migrations** — the 14 existing migrations are never edited.

### Concurrency

Assignment, transitions, references, CSAT, SLA breaches and inbound email are already guarded by row
locks or unique claims (confirmed by the June adversarial review). The new features add three more
concurrency-sensitive paths, each with an explicit guard: AI turns (a per-ticket lock plus a unique
"one AI turn per triggering message" record, and a re-check of the human-takeover flag under the lock
before anything is written), contact de-duplication (`createOrFirst` on a tenant-scoped unique email),
and conversation-to-ticket escalation (a locked conversation row so a double click opens one ticket).

### Tests

- Only SQLite is exercised; MySQL and PostgreSQL behave differently on JSON paths, string/integer
  comparison, unique NULLs and DDL in transactions. CI gains MySQL 8 and PostgreSQL 16 jobs running the
  whole suite.
- Laravel 11 is allowed by `composer.json` but never tested. The matrix becomes PHP 8.3/8.4/8.5 ×
  Laravel 11/12/13 × prefer-lowest/prefer-stable on Ubuntu and Windows (combinations a Laravel version
  does not support are excluded explicitly).
- Arch tests only check strict types, enums, contracts and tenant scoping. They grow to cover layering:
  the domain never depends on the HTTP layer, integrations are only reached through the service provider,
  internal classes are not used by contracts, no `env()` outside config.
- No test exercises realistic volumes. A volume suite seeds 10 000 tickets and asserts that the listing,
  metrics and SLA sweep run a bounded number of queries.

### Security (to re-verify at the end)

The final adversarial review (`docs/technical/hardening-v1.md`) re-checks cross-tenant isolation on every
new table, the widget surface (public keys, origin allow-list, HMAC identity, session tokens, rate limits,
honeypot), what leaves the system towards AI providers (prompt-injection fencing, PII), attachments,
webhooks, CSAT links and inbound email.

## Features added for the Selli standard

The reference use case is SalesMind's customer-support module: tickets from the app, from an AI chatbot
(also embedded on external sites, with an identified or anonymous visitor) and from email threads; a
customer-support role; an AI that answers with RAG over FAQs and a knowledge base and escalates to humans
with automatic assignment and supervisor notification; human takeover at any time; KPIs; MCP. Every
addition below maps to one of those needs.

### 1. External and anonymous requesters (`Contact`)

Not every requester is a user of the host app: an email sender or an anonymous widget visitor has only a
name and an email.

- `Contact` model (`ticket_contacts`): tenant-scoped, de-duplicated on the normalised email (unique per
  tenant, race-safe `createOrFirst`), optional link to a host model, `Notifiable` so it receives mail.
- `Ticketing::contacts()->remember($email, $name)`; contacts are regular requesters/participants.
- The email channel resolves senders to contacts by default, and adds CC addresses as `cc` participants.
- GDPR: anonymising a contact scrubs its name and email, the denormalised mail metadata and the AI turn
  excerpts; the export covers contacts like any requester. Tickets with neither subject nor host requester
  remain first-class.

### 2. Knowledge base (headless help center)

- Categories (nested), articles and FAQs (`type = article|faq`), each with a locale and a translation
  group.
- Draft → published → archived lifecycle, with an immutable version snapshot on every save.
- Visibility per tenant and audience: `public` (anyone, including anonymous widget visitors), `internal`
  (agents only), `customer` (signed-in requesters, optionally restricted to one host scope such as a
  client).
- "Was this helpful?" feedback, de-duplicated per voter.
- Article ↔ ticket links ("resolved with article X", "suggested").
- Keyword search with weighted ranking (title, keywords, body) that runs on every supported database —
  no external dependency. The RAG bridge upgrades it to hybrid semantic search when installed.

### 3. Conversational channel for chat and widgets

- **Widget keys:** public keys per tenant (optionally scoped to one customer), revocable, with an origin
  allow-list and an optional secret for HMAC-signed identity. Internal ids never reach the browser: the
  widget only knows its public key and an opaque conversation id.
- **Conversations:** a JSON API (opt-in, separately mounted) to start a conversation, post and read
  messages, ask for a human and close. A conversation turns into a ticket when a human is needed (AI
  escalation or the visitor's request) or straight away when AI is off for that tenant/type.
- **Abuse protection:** per-IP/per-key/per-conversation rate limits, a honeypot field, message size and
  count caps, hashed session tokens, CORS restricted to the allow-list, and a `HumanVerifier` contract for
  a captcha (Turnstile, hCaptcha…) bound by the host.
- **Reference JS client:** a small, dependency-free client published as an optional asset
  (`vendor:publish --tag=ticketing-widget`). It implements the protocol only (start, send, poll, escalate,
  identity) and renders nothing, so the package stays headless while every Selli front-end gets the
  protocol right — including token storage and signed identity — without reimplementing it.

### 4. Provider-agnostic AI layer

- One `AiDriver` contract (classify, answer with sources and confidence, summarise) behind an
  `AiManager` (`null` and `fake` drivers shipped; `rag-engine` from the bridge; `extend()` for hosts, for
  example on `laravel/ai`).
- Classification: category, priority, language, sentiment, "wants a human", sensitive topics.
- Escalation rules (all configurable): low confidence, no sources, human requested, sensitive topics,
  too many AI exchanges, negative sentiment, provider error.
- Modes per tenant and per ticket type — `auto` (AI replies), `draft` (AI writes an internal draft for an
  agent), `off` — resolved by an `AiSettingsResolver` contract (config-backed by default).
- Human takeover: an agent's public reply or an explicit `Ticketing::takeOver()` suspends the AI on that
  ticket; the AI never writes after a takeover (checked under the ticket lock).
- Escalation assigns through the routing engine and notifies the assignee and the team leads
  (`team_members.role = lead`), or whoever a host-bound `EscalationRecipients` contract returns.
- Every AI call is recorded (`ticketing_ai_turns`: outcome, reason, confidence, tokens, cost, model,
  latency) with dedicated events (`AiReplied`, `AiDraftCreated`, `TicketEscalated`, `HumanTookOver`,
  `AiTurnFailed`); the same table feeds the KPIs and the knowledge-gap report.

### 5. KPIs and reporting

`Ticketing::metrics()` returns aggregate metrics for a period and an optional filter (channel, type,
team, assignee, category, priority): volume, backlog (with age buckets), first response and resolution
(mean and median), SLA compliance per target, reopen rate, AI resolution and escalation rates with
reasons and cost, breakdowns per agent/team/channel/category, CSAT (average, distribution, response
rate) and the knowledge-base gaps (questions the AI could not answer, grouped). Exports stream CSV
(`StreamedResponse`, lazy cursor) with no spreadsheet dependency.

### 6. MCP tools (optional)

When `laravel/mcp` is installed (`suggest` only), the package provides a `TicketingServer` and a set of
tools a host can mount or register in its own server: list/get tickets, reply, internal note, assign,
transition, take over, KPI summary, knowledge-base search, read and save articles. Every tool resolves
the MCP user and authorizes through the package policies; destructive tools require an explicit
`confirm` flag.

### 7. Inbound email, completed

- Threading: `+t_` token, then `In-Reply-To`/`References` (indexed), then the ticket reference in the
  subject (`[SUP-2026-00042]`), each header- or subject-based match accepted only when the sender is
  already on the ticket.
- CC addresses become `cc` participants; quoted history and signatures are stripped from the stored body
  (the original is kept in `meta`).
- Anti-loop: auto-replies, bounces/DSNs (`multipart/report`, `X-Failed-Recipients`, mailer-daemon and
  postmaster senders, empty `Return-Path`), no-reply senders and the package's own outbound mail.
- Outbound notifications carry `Message-ID`, `In-Reply-To` and `References`, and AI replies carry
  `Auto-Submitted: auto-replied`.
- An `InboundMailbox` contract plus an optional IMAP implementation on `directorytree/imapengine`
  (`suggest`), polled by `ticketing:mail:poll` (schedulable, idempotent, moves or flags processed mail).

### 8. Other additions motivated by the audit

- `channel` on tickets (`web`, `email`, `widget`, `chat`, `api`, …) — needed for every per-channel KPI and
  for routing rules.
- Reopen on requester reply within a configurable window (a customer answering a resolved ticket), as
  SalesMind does today.
- `TicketQuery` (see Performance) as the single filtering surface for API, MCP and exports.

### Excluded, with reasons

| Item | Why it is not in 1.0 |
| --- | --- |
| A bundled UI (agent console, chat widget UI) | The package is headless by contract; the reference JS client covers the protocol. |
| First-party LLM provider drivers in the core | Duplicates the RAG Engine and `laravel/ai`. The `rag-engine` driver and the `AiDriver` contract cover both; the docs show a `laravel/ai` driver recipe. |
| XLSX export | Needs a heavy dependency; CSV opens in every spreadsheet tool. |
| Vector search inside the core | Delegated to the RAG bridge; the core ships portable keyword search. |
| Spatie state-class workflow bridge, tenancy and permission bridges | See "Claims that are not implemented". |
| Per-document crypto-shredding of RAG vectors | The RAG Engine purges per document and shreds per tenant; per-document key shredding is proposed upstream instead. |

## RAG Engine integration (optional bridge)

`sellinnate/rag-engine` stays in `suggest`; the bridge (`Selli\Ticketing\Integrations\RagEngine`) boots only
when the package is installed **and** `ticketing.integrations.rag_engine.enabled` is true. Without it every
feature above works on the keyword search and the configured AI driver.

- **Indexing:** published articles and FAQs, active canned responses and — opt-in — resolved tickets
  (question + final public answer, PII-redacted), synced on change through queued jobs, carrying tenant,
  visibility, audience, locale and source as filterable metadata. Shared (null-tenant) content is indexed
  under a dedicated RAG tenant and merged at query time, so isolation stays fail-closed in both packages.
- **AI driver `rag-engine`:** hybrid retrieval + generation through the RAG Engine LLM driver, citations
  stored on the message, relevance threshold feeding the escalation rules.
- **Deflection and similar tickets:** semantic article suggestions before a ticket is opened, similar
  resolved tickets for agents.
- **Right to erasure:** anonymisation, contact erasure and retention deletes purge (or re-index the
  anonymised version of) the related vectors.
- **Tests:** `sellinnate/rag-engine` in `require-dev`, `fake` embedder, `memory`/`database` stores, `fake` LLM.

## Phases

| Phase | Branch | Content |
| --- | --- | --- |
| 1. Foundation | `feature/v1-stable-foundation` | This plan; PHPStan level 8; claims and links cleanup; public API + `@internal`; arch tests; indexes, N+1 fixes, `TicketQuery`; CI matrix (Laravel 11–13) with MySQL and PostgreSQL jobs; volume tests. |
| 2. Support domain | `feature/v1-stable-support` | Contacts, knowledge base, AI layer, conversations and widget API, human takeover, escalation, reference JS client. |
| 3. Channels & insight | `feature/v1-stable-channels` | Inbound email completion and IMAP adapter; KPI service and CSV export; MCP tools. |
| 4. RAG bridge | `feature/v1-stable-rag` | `Integrations\RagEngine` (indexing, AI driver, deflection, similar tickets, GDPR purge). |
| 5. Docs & release | `feature/v1-stable-release` | Full docmd site, Cloudflare `wrangler.jsonc`, README with banner, CHANGELOG 1.0.0, `UPGRADE.md`, `SECURITY.md`, `CONTRIBUTING.md`, `.gitattributes`, `composer.json`, release runbook, final adversarial review (`hardening-v1.md`). |

Each phase keeps every gate green before it is merged: Pest (SQLite locally, plus MySQL and PostgreSQL),
PHPStan level 8, Pint, coverage ≥ 90 % in CI, a local Codex review and the CodeRabbit loop on the PR.
