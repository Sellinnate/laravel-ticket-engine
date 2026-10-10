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
published or saved and removed when archived; a ticket is indexed when resolved or closed and removed when
reopened, merged away or erased.

## What is indexed {#documents}

| Document | Content | Visible to |
| --- | --- | --- |
| Published article | title, summary, body | its [audience](/concepts/knowledge-base#audiences): `public`, `customer`, `internal`, and its client scope if restricted |
| Resolved / closed ticket | title and the **public** conversation — never an internal note | agents only (`internal`) |

Each document carries filterable metadata: `type`, `visibility`, `audience` (`*` or the client scope),
`locale`, `article_type`, `category`, `source_id`, `url`. Editing an article replaces its indexed version
(the old one is purged, not just hidden).

## Fail-closed by construction {#security}

- **Tenants**: each ticketing tenant is its own RAG tenant (`tk-<tenant>`; shared rows in `tk-shared`, which
  every tenant also searches when `tenancy.allow_shared` is on). The engine forces the tenant into every
  query, so a search cannot reach another tenant.
- **Audience**: searches only use positive conditions — `visibility IN (what this audience may read)`,
  `audience IN ('*', this client)`, `type IN (…)`. A document with missing or unexpected metadata matches
  nothing.
- **Double check**: the semantic knowledge-base search re-reads every hit from the database through the
  article audience scope, so an article unpublished or restricted since it was indexed is never returned.
- **Prompt injection**: retrieved passages and the conversation are fenced as untrusted data in the prompt.

## The `rag-engine` AI driver {#driver}

```php
'ai' => [
    'driver' => 'rag-engine',
    'drivers' => [
        'rag-engine' => [
            'llm' => null,              // a rag-engine LLM name; null = its default
            'classify' => true,         // classify each message through the LLM (JSON)
            'include_tickets' => false, // agents' drafts may also cite resolved tickets
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
  not survive in the index.
- A ticket erased by retention (`delete`), merged away or reopened is removed from the index.
- Deleting a whole tenant: `app(RagKnowledge::class)->shredTenant($tenantId, 'reason')` crypto-shreds it in
  the engine (vectors, chunks and keys; the engine refuses any later ingest for that tenant).

## Testing

The bridge's own tests run on the engine's in-memory vector store and fake LLM. In yours, switch the engine to
`rag-engine.defaults.vector_store = memory`, `embedder = fake`, `llm = fake`, set `ticketing.rag.queue` to
`false`, and register `RagEngineServiceProvider` in Testbench.
