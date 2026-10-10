---
title: "MCP tools"
description: "Let AI agents (Claude, Cursor, your own) work the help desk through Model Context Protocol tools that obey your policies."
type: guide
---

# MCP tools

An optional [Model Context Protocol](https://modelcontextprotocol.io) server over the help desk, built on
[`laravel/mcp`](https://github.com/laravel/mcp). An AI agent connected to it can list and read tickets,
answer customers, leave notes, assign, resolve, take over from the AI assistant, read the KPIs and work on
the knowledge base — **as the authenticated user, with that user's permissions**.

::: callout info "In plain words"
Your support team can ask their AI assistant "what's waiting for me?", "summarise SUP-2026-00042",
"reply that the fix ships tomorrow" or "draft an FAQ from this ticket" — and the assistant does it through
the same rules as your app: it never sees another tenant, a customer never sees internal notes, and it
cannot close a ticket without an explicit confirmation.
:::

## Install

```bash
composer require laravel/mcp    # Laravel 12.41+ / 13
```

Mount the server behind your authentication (Sanctum, Passport, or the OAuth flow `laravel/mcp` provides):

```php
// routes/ai.php
use Laravel\Mcp\Facades\Mcp;
use Selli\Ticketing\Mcp\TicketingServer;

Mcp::web('/mcp/ticketing', TicketingServer::class)->middleware(['auth:sanctum', 'your-tenant-middleware']);
```

Already have an MCP server? Add the tools to it instead:

```php
protected array $tools = [...TicketingServer::TOOLS, MyOtherTool::class];
```

## The tools {#tools}

| Tool | Does | Ability |
| --- | --- | --- |
| `list-tickets` | list with the [listing filters](/concepts/querying) (`state`, `status`, `priority`, `type`, `channel`, `team`, `assignee`, `tag`, `q`, dates; `limit` ≤ 50) | `viewAny` — customers only see their own tickets |
| `get-ticket` | one ticket by reference or id, with its last 50 messages | `view`; internal notes need `viewInternal` |
| `reply-to-ticket` | public reply (the customer is notified) | `comment` |
| `add-internal-note` | internal note | `commentInternal` |
| `assign-ticket` | `assignee: "me"` and/or a `team` key | `assign` |
| `transition-ticket` | run a workflow transition (resolve, close, reopen…) — **needs `confirm: true`** | `transition` |
| `take-over-ticket` | stop the AI on the ticket; assigns an unowned ticket to you | `takeOver` |
| `ticket-metrics` | the [KPIs](/concepts/reporting) of a period, with filters | `viewReports` |
| `search-knowledge-base` | search the articles the user may read | article `view` |
| `get-article` | one article by slug | article `view` |
| `save-article` | create a draft, or update a draft by id — **never publishes nor edits a published article** | article `create` / `update` |

Read-only tools are annotated `readOnlyHint`, `transition-ticket` `destructiveHint`, so MCP clients can ask
the person before running them.

## Security model

- **No user, no tool**: every call needs an authenticated user; the tools act as that user.
- **Same policies as the REST API**: `TicketPolicy`, `KbArticlePolicy` (or yours). An unknown ticket and a
  forbidden one give the same "not found" answer, so a client cannot probe references.
- **Tenant scope**: queries run through the tenant scope of the request — mount the server behind the
  middleware that sets your tenant.
- **Humans stay in charge** of what the customer sees: workflow transitions need an explicit
  `confirm: true`, and articles the agent writes stay drafts until a person publishes them.

::: callout warning "Prompt injection"
Ticket messages and articles are customer- or user-written text: an agent reading them can be told to do
things by that text. Keep confirmations on in your MCP client for write tools, and do not grant the MCP user
more than the person using it has.
:::

## Testing your setup

```php
use Selli\Ticketing\Mcp\TicketingServer;
use Selli\Ticketing\Mcp\Tools\ListTicketsTool;

TicketingServer::actingAs($agent)
    ->tool(ListTicketsTool::class, ['state' => 'open', 'assignee' => 'me'])
    ->assertOk();
```
