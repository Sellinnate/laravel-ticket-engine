---
title: "An AI help desk with RAG"
description: "From an empty knowledge base to an AI first line that answers with sources, hands over to people and learns what is missing."
type: guide
---

# An AI help desk with RAG

This guide builds a complete AI-assisted help desk: a knowledge base, semantic retrieval through the
[RAG Engine bridge](/integrations/rag-engine), an AI that answers customers with sources on the widget and by
email, clear rules for when a person takes over, and the KPIs that tell you what to write next.

::: callout info "In plain words"
Customers ask; the AI answers from your articles and says where the answer comes from. When it is not sure,
finds nothing, or the customer wants a person, the ticket goes to your team — already classified, summarised,
and assigned. Every week you look at the questions nobody could answer and write the missing articles.
:::

## 1. Write the knowledge the AI may use

Articles and FAQs are the only knowledge the AI answers from. Give each one the right **audience**:

```php
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\{ArticleType, KnowledgeVisibility};

$article = Ticketing::knowledge()->save(new ArticleData(
    title: 'Reset your password',
    body: "Open **Settings → Security** and choose *Reset password*…",
    type: ArticleType::Article,
    visibility: KnowledgeVisibility::Public,   // public | customer | internal
    locale: 'en',
), author: $agent);

Ticketing::knowledge()->publish($article, $agent);
```

- `public` — anyone, including anonymous widget visitors; `customer` — identified customers; `internal` —
  agents only (runbooks, escalation contacts). Restrict an article to one client with `audience: $client`.
- Translations are separate articles in the same translation group (`translationOf:`).
- Drafts are never used; publishing is a deliberate step. See [Knowledge base](/concepts/knowledge-base).

## 2. Turn on semantic retrieval

```bash
composer require sellinnate/rag-engine
php artisan vendor:publish --tag=rag-engine-migrations && php artisan migrate
```

Configure the engine (embedding provider, vector store, LLM, key management) following its documentation,
then the bridge:

```php
// config/ticketing.php
'rag' => [
    'enabled' => true,
    'knowledge_search' => true,      // the help-center search becomes semantic too
    'min_score' => 0.35,             // tune after looking at real scores
    'article_url' => 'https://help.acme.example/{locale}/{slug}',
],
```

```bash
php artisan ticketing:rag:index   # backfill once; afterwards the index follows every change
```

Each tenant's documents live in its own RAG tenant, and every search filters on audience with positive
conditions only — an internal article can never reach a customer, and a hit is re-checked in the database
before anyone sees it.

## 3. Let the AI answer

```php
'ai' => [
    'enabled' => true,
    'driver' => 'rag-engine',
    'mode' => 'off',                                  // safe default…
    'modes' => [
        'types' => ['support' => 'auto', 'incident' => 'draft'],   // …then opt in per type
        'tenants' => [12 => 'off'],                                 // or per tenant
    ],
    'queue' => true,                                  // run turns on the queue
    'escalation' => [
        'min_confidence' => 0.6,
        'require_sources' => true,
        'sensitive_topics' => ['billing', 'legal', 'privacy', 'complaint'],
        'max_exchanges' => 3,
        'team' => 'support-desk',                     // owner when routing finds none
        'notify_leads' => true,
        'handoff_message' => 'Thanks — a member of our team will get back to you shortly.',
        'summarize' => true,
    ],
],
```

- **auto** — the AI replies publicly, citing sources (`meta.ai.sources` on the message, links in the email).
- **draft** — the AI writes an internal draft; an agent reviews and sends.
- The AI hands over when the customer asks for a person, a sensitive topic appears, it is not confident,
  found no source, or it has answered too many times; a provider error always hands over. The ticket is
  routed (your routing rules, then the escalation team), the team leads are notified, and the agent finds an
  AI summary as an internal note. See [AI assistance](/concepts/ai#escalation).
- Run a queue worker; the AI turn runs after the customer's message is committed.

## 4. Open the channels

- **Website chat** — a widget key per site, the reference JS client, signed identities for logged-in users:
  follow [A widget on an external site](/guides/external-widget).
- **Email** — webhook or IMAP polling; replies thread back, quotes are stripped, autoresponders ignored:
  see [Email-to-ticket](/channels/email). AI replies are flagged `Auto-Submitted`, so customers'
  autoresponders stay quiet.
- **Your app** — the REST API or the facade (`Ticketing::open()`), with the same AI behaviour.

## 5. Give agents the controls

- **Take over** at any moment: `Ticketing::takeOver($ticket, $agent)` or `POST /tickets/{id}/takeover`. An
  agent's public reply does it implicitly; the AI never writes on that ticket again.
- **Similar tickets** while working on one:

  ```php
  app(\Selli\Ticketing\Integrations\RagEngine\RagKnowledge::class)->similarTickets($ticket->title, $tenantId);
  ```

- **AI assistants for agents** through [MCP tools](/integrations/mcp): "what's waiting for me?", "summarise
  SUP-2026-00042", "draft an FAQ from this ticket" — under each agent's own permissions.

## 6. Measure and close the loop

```php
$m = Ticketing::metrics()->between(now()->subWeek(), now());

$m->ai();               // AI-handled tickets, resolved by the AI, escalated (and why), cost per currency
$m->knowledgeGaps();    // questions the AI could not answer, grouped, most asked first
$m->firstResponse();    // humans' first response time (AI replies do not stop the SLA clock)
$m->csat();             // what customers thought
```

Every week: take the top knowledge gaps, write (or have an agent draft through MCP) the missing articles,
publish them — the index updates by itself — and watch the AI resolution rate and the escalation reasons.
Listen to `AiUsageRecorded` for budgets and alerts.

## 7. Test it

```php
config()->set('ticketing.ai.driver', 'fake');           // deterministic, scriptable
$fake = app(\Selli\Ticketing\Ai\AiManager::class)->driver('fake');
$fake->queueAnswer(new AiAnswer('Open Settings…', [$source], 0.9));
$fake->queueClassification(new AiClassification(wantsHuman: true));
```

For the RAG bridge, use the engine's in-memory vector store and fake LLM (see
[RAG Engine bridge → Testing](/integrations/rag-engine)).

## Go-live checklist

- [ ] Queue worker running; `ticketing:escalate` (SLA) and, with IMAP, `ticketing:mail:poll` scheduled.
- [ ] AI enabled per type/tenant, not globally, at first; draft mode for the riskiest types.
- [ ] Escalation team and team leads (`team_members.role = lead`) set up per tenant.
- [ ] Articles reviewed for audience: nothing internal marked `public`.
- [ ] `min_score` and `min_confidence` tuned on real questions.
- [ ] Data protection: the LLM/embedding providers receive customer text — choose providers and regions
      accordingly, and keep [GDPR erasure](/security/gdpr) wired (it purges the RAG index too).
