---
title: "RAG Engine bridge"
description: "Semantic knowledge-base search, AI answers with citations and similar tickets on sellinnate/rag-engine — tenant-isolated and fail-closed."
type: guide
---

# RAG Engine bridge

An optional bridge to [`sellinnate/rag-engine`](https://laravel-rag-engine.selli.io): your published
articles and resolved tickets go into a RAG index, and the help desk uses it for **semantic search**, an
**AI driver that answers with citations**, and **similar tickets** for agents.

::: callout info "In plain words"
Instead of matching keywords, the help center understands the question: "I can't get into my account"
finds the article "Reset your password". The AI answers customers from those articles — quoting them —
and agents see how similar tickets were solved. A customer can never be shown an internal document, and
one tenant never sees another's.
:::

## Install

```bash
composer require sellinnate/rag-engine
php artisan vendor:publish --tag=rag-engine-migrations && php artisan migrate   # the engine's tables
```

Configure the engine (embedder, vector store, LLM, key management) as its documentation explains, then turn
the bridge on and backfill:

```php
// config/ticketing.php
'rag' => [
    'enabled' => true,
    'knowledge_search' => true,       // Ticketing::knowledge()->search() becomes semantic
    'min_score' => 0.35,              // passages below are ignored — tune it to your embedder
    'top_k' => 5,
    'article_url' => 'https://help.example.com/{locale}/{slug}',   // links in citations
],
'ai' => ['driver' => 'rag-engine'],
```

```bash
php artisan ticketing:rag:index           # --articles / --tickets for one kind only
```

From then on the index follows the help desk by itself (queued, after commit): an article is indexed when
published or saved and removed when archived; a ticket is indexed when resolved or closed (and refreshed on a
late public reply, a split or a merge) and removed when reopened, merged away, anonymised or erased; a canned
response follows its saves. Choose which with `rag.index.articles`, `.tickets`, `.canned_responses`.

**Retrieval** is hybrid by default (`rag.hybrid`): passages must clear `min_score` on the vector similarity —
a score a threshold means something on — and are then ordered by vectors and exact words together (BM25,
fused by reciprocal rank), so product names and error codes rank well. For generation, the engine's LLM
driver is used — an Anthropic model is the recommended choice.

## What is indexed {#documents}

| Document | Content | Visible to |
| --- | --- | --- |
| Published article | title, summary, body | its [audience](/concepts/knowledge-base#audiences): `public`, `customer`, `internal`, and its client scope if restricted |
| Resolved / closed ticket | title, the customer's question and the final public answer (agent or AI) — never an internal note — with emails, phone, card and IBAN numbers and the names of the people on the ticket removed | agents only (`internal`), while it stays resolved |
| Active canned response | name and body | agents only (`internal`) |

Each document carries filterable metadata: `type`, `visibility`, `audience` (`*` or the client scope),
`locale`, `article_type`, `category`, `source_id`, `url`. Editing an article replaces its indexed version
(the old one is purged, not just hidden).

## Fail-closed by construction {#security}

- **Tenants**: each ticketing tenant is its own RAG tenant, named one-to-one — `tk-n-42` for a plain id,
  `tk-b-<base64url>` for any other, `tk-shared` for shared rows, which every tenant also searches when
  `tenancy.allow_shared` is on. The engine forces the tenant into every query, so a search cannot reach
  another tenant.
- **Audience**: searches only use positive conditions — `visibility IN (what this audience may read)`,
  `audience IN ('*', this client)`, `type IN (…)`. A document with missing or unexpected metadata matches
  nothing.
- **Double check**: every hit is re-read from the database — articles through the audience scope, tickets
  for agents only — before anyone sees its text, the AI's prompt included. The index follows the help desk
  asynchronously; an article unpublished, restricted or moved since it was indexed is never used.
- **Prompt injection**: retrieved passages and the conversation are fenced as untrusted data in the prompt.

## The `rag-engine` AI driver {#driver}

```php
'ai' => [
    'driver' => 'rag-engine',
    'drivers' => [
        'rag-engine' => [
            'llm' => null,              // a rag-engine LLM name; null = its default
            'classify' => true,         // classify each message through the LLM (JSON)
            'internal_sources' => false, // an assistant for agents may also cite resolved tickets and canned responses
        ],
    ],
],
```

- **Answer**: the passages the customer's audience may read, above `min_score`, become the context; the
  model answers citing `[1]`, `[2]`… and the reply carries the sources (title, url, score). No passage — or
  a model replying `NO_ANSWER` — means no answer, so the [escalation rules](/concepts/ai#escalation) hand the
  ticket to a person. The confidence is the best passage's score.
- **Classify**: the model returns language, sentiment, priority, category, "wants a human" and sensitive
  topics; a reply that is not JSON simply means no classification.
- **Summarise**: a short summary for the agent who takes over.
- The engine does not report token usage: AI turns record the model, not tokens or cost.

## Similar tickets and deflection {#similar}

```php
use Selli\Ticketing\Integrations\RagEngine\RagKnowledge;

$similar = app(RagKnowledge::class)->similarTickets($ticket->title, $tenantId, limit: 5);  // agents
$articles = Ticketing::knowledge()->suggest($question, KnowledgeAudience::public());       // before a ticket opens
```

## GDPR {#gdpr}

- Anonymising a requester (or a contact) purges the vectors of **their** tickets right away — their words do
  not survive in the index — and no backfill or late job ever indexes them again: the core records
  `requester.anonymized` (and retention `ticket.anonymized`) in each ticket's audit trail even when the bridge
  is off, and the bridge adds `rag.excluded`. The marker follows the messages: a ticket that merges them in or
  is split off from a marked one is marked `ticket.erasure_inherited`, also before retention deletes the
  marked ticket and its trail.
- A ticket anonymised or erased by retention, merged away or reopened is removed from the index (the new
  `TicketAnonymized` event tells integrations about retention anonymisation).
- Deleting a whole tenant: `app(RagKnowledge::class)->shredTenant($tenantId, 'reason')` crypto-shreds it in
  the engine (vectors, chunks and keys; the engine refuses any later ingest for that tenant).

## Testing

The bridge's own tests run on the engine's in-memory vector store and fake LLM. In yours, switch the engine to
`rag-engine.defaults.vector_store = memory`, `embedder = fake`, `llm = fake`, set `ticketing.rag.queue` to
`false`, and register `RagEngineServiceProvider` in Testbench.
