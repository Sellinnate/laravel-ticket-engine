---
title: "Knowledge base"
description: "A headless help center: articles and FAQs per locale, drafts and versions, audiences, feedback, ticket links and search."
type: concept
---

# Knowledge base

A **headless help center** inside the package: categories, articles and FAQs, in several languages, with
drafts, versions, per-audience visibility, "was this helpful?" votes, links to tickets and a search that works
on every database. You build the pages; the package keeps the content honest.

::: callout info "In plain words"
Write an answer once, publish it, and every channel can use it: customers find it before opening a ticket,
the AI quotes it, agents link it to the ticket they solved with it — and you can see which answers help.
:::

## Writing and publishing

```php
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Facades\Ticketing;

$kb = Ticketing::knowledge();

$article = $kb->save(new ArticleData(
    title: 'Reset your password',
    body: "Open **Settings → Security** and choose *Reset*.",
    type: ArticleType::Article,             // or ArticleType::Faq
    locale: 'en',
    summary: 'Settings → Security → Reset.',
    keywords: ['password', 'login'],
    visibility: KnowledgeVisibility::Customer,
), author: $agent);

$kb->publish($article);                     // and $kb->archive($article)
```

- A new article is a **draft**; only **published** articles are visible or searchable.
- Every content change (title, summary, body, format, keywords) bumps `version` and writes an immutable
  `KbArticleVersion` snapshot — the history of what customers were told is never lost.
- Slugs are unique per tenant and locale (`billing-faq`, `billing-faq-2`, …).
- Translations: pass `translationOf: $english` when saving the Italian version; both share a
  `translation_group` and `$kb->translation($article, 'it', $audience)` finds it.

## Audiences {#audiences}

Every read goes through a `KnowledgeAudience`, so a caller can never see an article it is not meant to.

| Audience | Sees | Built with |
| --- | --- | --- |
| Anonymous visitor | `public` articles | `KnowledgeAudience::public($scope = null)` |
| Identified requester | `public` + `customer` | `KnowledgeAudience::customer($scope = null)` |
| Agent | everything, including `internal` runbooks | `KnowledgeAudience::agent()` |

`KnowledgeAudience::for($user)` picks the right one from your user model (`CanActOnTickets` → agent,
`CanRequestTickets` → customer). An article can be restricted to **one audience scope** (`audience:` a host
model such as a client): it is then visible only within that scope — for example on that client's widget.

## Searching {#search}

```php
$results = $kb->search('reset my password', KnowledgeAudience::customer(), locale: 'en', limit: 5);

foreach ($results as $result) {
    $result->article;   // KbArticle
    $result->score;     // 0..1
    $result->excerpt;   // the sentence around the first match
}

$kb->suggest($subject, KnowledgeAudience::public());   // deflection: up to 3 articles
```

The built-in search is a portable **keyword search** (no external engine): the query is split into words,
the database keeps the articles that match any of them, ranked by where they match (title 5, keywords 3,
summary 2, body 1), and the best candidates are re-ranked on term frequency. It runs the same SQL on SQLite,
MySQL, PostgreSQL and SQL Server, and handles accented words on SQLite too.

The search sits behind the `KnowledgeSearch` contract, so a semantic implementation can replace it: bind your
own in a service provider.

## Feedback and ticket links

```php
$kb->feedback($article, helpful: true, voter: $user);              // or an opaque string for anonymous readers
$kb->link($article, $ticket, ArticleLinkRelation::ResolvedBy, $agent);
$article->helpfulness();                                           // share of helpful votes, or null
```

Each voter counts once per article (a changed vote moves the counters); links are unique per relation,
audited on the ticket and refused across tenants.

## Over HTTP

The [REST API](/channels/rest-api#knowledge-base) exposes the knowledge base to signed-in users (agents
write and publish, everyone reads by audience); the [widget API](/channels/widget#knowledge-base) serves the
public help center to embedded widgets by slug.

## Events

`ArticleSaved`, `ArticlePublished`, `ArticleArchived`, `ArticleFeedbackRecorded`, `ArticleLinkedToTicket`.

## Best practices

- Keep `summary` short and self-contained: the fake and RAG AI drivers quote it as the answer.
- Use `customer` for anything that assumes an account, `internal` for agent procedures.
- Review articles with many "not helpful" votes regularly, and the questions the AI escalated for lack of
  sources (`ai_turns` with reason `no_sources`).
