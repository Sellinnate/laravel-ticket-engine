---
title: "v1 hardening — adversarial review"
description: "How the 1.0.0 code was attacked before release, what was found, how each finding was fixed and tested, and the risks that remain."
type: reference
---

# v1 hardening — adversarial review

Before 1.0.0 every phase of the release ([plan](/technical/v1-release-plan)) went through an adversarial
review: the reviewer's job was to break tenant isolation, leak data to the wrong audience, make the system
do something twice or not at all under concurrency, or make it lie in its numbers. This document records
the method, the findings by area, and what is left.

::: callout info "In plain words"
We tried hard to make the package misbehave — two workers at the same time, a crash halfway, a visitor
crafting requests, an email loop, a stale search index, another tenant's id — and fixed everything we
found, each time with a test that would catch it again.
:::

## Method

- **Automated adversarial review** of every change set (OpenAI Codex review) until it reported nothing
  actionable — 42 rounds on the support phase, 18 on channels, 29 on the RAG bridge, plus the documentation
  — and CodeRabbit on every pull request.
- **Every finding fixed with a regression test**, verified to fail without the fix.
- **Three databases on every change**: SQLite, MySQL 8.4 and PostgreSQL 16 run the whole suite (JSON paths,
  string/integer comparisons, NULLs in unique indexes, row locks and isolation levels differ).
- **Volume tests** on 10 000 tickets bound the number of queries of listing, routing, SLA and KPIs.
- **Static analysis** at PHPStan level 8 without baseline; architecture tests (domain never depends on HTTP,
  contracts never reach internals, events after commit, no `env()`).

## Findings and fixes

### Tenant isolation

| Finding | Fix |
| --- | --- |
| A knowledge-base article could be viewed, or a category used, across tenants | Policy and category checks in the article's own tenant |
| Shared articles writable by a tenant agent; slugs of shared articles not unique at database level | Shared articles read-only for tenant agents; non-null `slug_key` unique index |
| A shared article could shadow the tenant's own one with the same slug | The tenant's own article wins, deterministically |
| A requester of another tenant could be attached to a ticket, or reach one through a participant row | `OpenTicket` refuses it; `isOnTicket` requires the ticket's tenant |
| Listeners running after commit (outside the tenant context) could not resolve a contact author or participant — requester email replies never reopened tenant tickets | Person relations resolve their row whatever the ambient tenant |
| Merged widget conversations kept the source's tenant | They take the target's tenant |
| Distinct tenant ids (`acme.eu` / `acme-eu`, `shared`) collapsed into one RAG tenant | One-to-one RAG tenant naming |
| Streamed CSV exports read later chunks in whatever tenant was current | Each chunk is read in the tenant the export was asked in |

### Audiences and authorization

| Finding | Fix |
| --- | --- |
| Knowledge-base listings could show what a direct read refuses | Every result passes the article policy |
| The AI could put a stale excerpt (article made internal, restricted or archived, not re-indexed yet) into a customer's answer | Every RAG hit is re-read in the database — audience, tenant, current version — before use |
| A ticket passage could be cited after the ticket was reopened, split or merged | Ticket hits must still be resolved and match the indexed conversation revision |
| A requester who can also act on tickets had their own reply taken for the answer, or their name kept | Answers and names decided by role on the ticket and the requester contract |
| MCP references could pick a shared ticket over the tenant's own; reports were open to agents of another tenant | The tenant's own ticket wins, ambiguity is refused; `viewReports` requires membership of the current tenant |
| MCP tools could reach the database with keys that are not keys (bigint vs ULID), or edit a published article | Key validation per id strategy; drafts only, checked under the article lock |
| A KB category filter that is not an id crashed PostgreSQL | Validated as a key (422) |
| An agent could create an article in another tenant (the one resolved for the request) or a shared one | `KbArticlePolicy::create` applies the write rule to the current tenant |

### AI under concurrency

| Finding | Fix |
| --- | --- |
| A retried job, a reclaimed turn or a stale worker could answer twice, overwrite, or count usage twice | One turn per triggering message (unique `trigger_key`); an `attempts` generation fences the old worker; ownership re-checked under lock; stale failures emit nothing |
| A takeover landing while the model was thinking could be overridden; classification could overwrite an agent's change | Every AI write re-checks the stored status under the ticket lock; priority is raise-only against the stored value |
| A question split or merged into another ticket while the model was thinking could still be answered — or, on a provider error, escalated — on the original ticket | The turn re-reads, under the ticket lock, that the triggering message is still on the ticket (and the ticket still exists) and is skipped otherwise |
| Two retries reclaiming the same stalled AI turn at once could both believe they owned it | The reclaim matches the generation the worker saw; the winner keeps the generation it wrote |
| Crashes between the hand-over steps left tickets half-escalated or announced twice | Hand-overs are resumable step by step (`handoff` turns), messages posted once under lock, the escalation announced once (audited marker) |
| Escalation and takeover could overwrite an assignment made meanwhile | Owner decided and assigned under the ticket lock |
| Providers were called for resolved tickets, off-mode tickets, or summaries with AI disabled | Checked before any call; no job queued for off-mode tickets |
| A question split into another ticket was answered on the original | Jobs check the message still belongs to the ticket |
| A closed ticket could be "escalated" with a promise of human follow-up | Refused (`EscalationNotAllowedException`, 422) |
| Plain-text questions lost anything looking like markup (`<PermissionDenied>`, `<jane@example.com>`) | Markup is stripped from HTML messages only |

### Public widget

| Finding | Fix |
| --- | --- |
| A signature over a user id also verified an unsigned email claim | The signature covers the user id, or the lower-cased email — never one for the other |
| The per-key limit could be dodged with header spellings; a case-insensitive collation accepted other spellings of a key | Counted on the resolved key; exact match required |
| A `429` without CORS headers was unreadable by the browser; middleware priority put the throttle first | CORS and key resolution ranked before the throttle |
| Interrupted hand-overs (ticket committed, side effects not) never completed | Tracked and resumed on the next escalation or AI retry, announced once |
| The message cap locked visitors out after a person took over; anonymous visitors' replies showed as "system" | Cap only in the AI phase; role from the widget source |
| The JS client failed where storage is denied | Falls back to memory |

### Email

| Finding | Fix |
| --- | --- |
| IMAP polling acknowledged rate-limited messages — they were lost | Explicit ingestion outcome; "not now" leaves the message unread |
| Poison messages starved the mailbox; retry state was lost on acknowledgement errors and reused across UIDVALIDITY | Back-off per message, set aside after `max_attempts`, waiting UIDs excluded from the IMAP search, state saved in `finally`, keyed by UIDVALIDITY |
| An ambiguous subject reference could thread into the wrong ticket | More candidates than checked ⇒ no match |
| A support mailbox copied on an email became a participant (mail loop) | Own addresses (reply-to base, sender, mailboxes, routes, configured list) excluded |
| A Message-ID longer than 255 characters crashed PostgreSQL | Hashed when longer than the column |
| A stored, non-RFC Message-ID could break outgoing mail; customer Markdown links became clickable in agents' mail | Invalid ids never used in headers; links neutralised |
| A host user on the ticket (requester, subject, agent) who never emailed could not thread a reply by header or subject | The sender check also matches the requester/agent addresses of host-model participants and of a requester subject |

### Personal data

| Finding | Fix |
| --- | --- |
| AI question excerpts, widget traces, vote comments survived erasure | Scrubbed by erasure and retention |
| Erased requesters' conversations could re-enter the RAG index (backfill, late job, merge, split, bridge off at the time, source deleted by retention) | Durable erasure markers recorded by the core (`requester.anonymized`, `ticket.anonymized`), carried as `ticket.erasure_inherited` through merge, split and retention deletes, plus the bridge's `rag.excluded`; the merge/split lineage followed to its end, checked under the document lock and at search time; recorded before the (fallible, retried) purges |
| Names of a requester the ticket is about, or of people hidden by a host's own tenancy scope, stayed in indexed tickets; tenant-scoped agents' answers were missed by queued syncs | People loaded without any global scope for redaction; agent answers recognised by the author's class |
| A RAG kind switched off came back through late jobs and stayed searchable | Switched-off kinds are never searched, purged by every sync and by `ticketing:rag:index` |
| Indexed tickets carried whole conversations, names and contact data | Only the question and the final answer, with emails, phone/card/IBAN numbers and the names of the people on the ticket removed |
| The AI's hand-over notice could be indexed as a resolved ticket's answer | Hand-over notices are never an answer; a ticket without a real answer is not indexed |
| Retention anonymisation left the ticket in the RAG index | New `TicketAnonymized` event; the bridge excludes and purges it |
| Contacts' unique key allowed one NULL on SQL Server (second anonymisation failed) | Filtered unique index on SQL Server |

### Metrics

| Finding | Fix |
| --- | --- |
| Cloning a `TicketQuery` shared its builder: the backlog filter leaked into later KPIs | Deep clone |
| Late or unswept SLA deadlines counted as met/running | Compared with `due_at` and now |
| Tickets an agent took before the AI ran counted as AI-handled; filtered metrics dropped conversation turns | AI involvement from actual turns |
| Offset dates compared in the wrong timezone; SQL Server averaged integers | Converted to the app timezone; averages cast to float |
| CSV cells could run as spreadsheet formulas | Quoted |
| A split ticket lost the source's channel and language (out of channel reports, routed and AI-configured without them) | Copied from the source |

## Residual risks

- **SQL Server** is supported by the SQL the package writes but not run in CI.
- **Prompt injection** cannot be ruled out: retrieved and customer text is fenced and the AI can only post a
  public reply or a draft, but a model can still be persuaded to say something wrong. Keep `draft` mode for
  sensitive types and confirmations on in MCP clients.
- **LLM classification** (RAG driver) depends on the model returning JSON; anything else means "no
  classification".
- **The RAG engine does not report token usage**: AI turns on the `rag-engine` driver record the model only.
- **Queued indexing lags**: until a sync job runs, stale hits are dropped (verification), so an article may be
  missing from search for a moment — never wrong.
- **Proxies**: per-IP rate limits need Laravel's trusted proxies configured correctly.
- **Widget tokens** live in the visitor's `sessionStorage`: an XSS on the host page can read them; use a CSP.
- **MySQL with ULID ids** needs `Schema::defaultStringLength(191)` (original migrations cannot change).

## Recommendations to hosts

Keep `APP_KEY` and widget secrets secret, put the REST API and the MCP server behind authentication and the
tenant middleware, schedule the sweeps, run a queue worker, review article audiences, and pick AI providers
that match your data-protection obligations. See [Security model](/security/model).
