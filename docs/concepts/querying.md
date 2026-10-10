---
title: "Listing & filtering tickets"
description: "TicketQuery: one tenant-scoped filtering surface for your code and the REST API."
type: concept
---

# Listing & filtering tickets

`Ticketing::tickets()` returns a `TicketQuery`: a fluent, tenant-scoped listing of tickets. The REST API's
`GET /tickets` uses it too, so a filter means the same thing in your code and over HTTP.

::: callout info "In plain words"
Instead of hand-writing `Ticket::query()->where(...)` in every controller, you describe *which* tickets you
want ("open, high priority, assigned to me, tagged VIP") and the query object turns it into indexed SQL —
always inside the current tenant.
:::

```php
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Facades\Ticketing;

$page = Ticketing::tickets()
    ->state('open')                         // open | closed | paused, per each type's workflow
    ->priority([Priority::High, Priority::Urgent])
    ->assignedTo($agent)                    // or ->unassigned(), ->forTeam($team)
    ->taggedWith('vip')
    ->search('printer jam')                 // exact reference, or title contains every word
    ->sort('priority', 'desc')
    ->paginate(25);                         // or ->cursorPaginate(25), ->get(), ->count()
```

## Filters

| Method | Loose key (`filter()`) | Matches |
| --- | --- | --- |
| `state('open')` | `state=open` | Tickets whose status maps to the semantic in **their own type's** workflow |
| `status(['new', 'triaged'])` | `status=new,triaged` | Raw workflow states |
| `priority(Priority::High)` | `priority=30,40` | One or more priorities (integer weights) |
| `type('incident')` | `type=incident` | Ticket type keys |
| `category('billing')` | `category=billing` | Categories |
| `channel('email')` | `channel=email,widget` | Arrival channel (`web`, `api`, `email`, `widget`, `chat`, `agent`, or your own) |
| `assignedTo($agent)` / `unassigned()` | `assignee=me`, `assignee=none`, `assignee=App\Models\User:5` | The current assignee |
| `forTeam($team)` / `teamKey('3')` | `team=3` | The owning team |
| `taggedWith('vip')` | `tag=vip,urgent` | Any of the tag slugs |
| `requestedBy($model)` | — | The requester participant |
| `about($order)` | — | The polymorphic subject |
| `involving($model)` | — | Subject **or** any participant — what a requester may list |
| `createdBetween($from, $to)` | `created_from=2026-01-01&created_to=2026-01-31` | Creation date range |
| `search('printer jam')` | `q=printer jam` | Exact reference, or a title containing every word (any order, case-insensitive; LIKE wildcards are escaped) |
| `sort('priority', 'asc')` | `sort=priority`, `sort=-created_at` | `created_at`, `updated_at`, `priority`, `due_at`, `resolved_at`, `reference` |

`filter(array $filters, ?Model $actor = null)` applies the loose keys from an HTTP query string or tool
arguments. Lists can be comma-separated strings or arrays (`?status[]=new&status[]=triaged`). A malformed
value never widens the result: an unknown sort is ignored, `assignee=me` without an actor and a team key
that cannot be an id match **nothing**.

For anything the object does not model, constrain the builder directly:

```php
Ticketing::tickets()->where(fn ($query) => $query->where('custom_fields->vip', true))->get();
```

::: callout info "Case-insensitive search on SQLite"
MySQL and PostgreSQL fold case for every alphabet. SQLite without the ICU extension folds only ASCII, so for
words with accented letters the search also tries the spellings text normally uses (as typed, lower case,
capitalised, upper case).
:::

## Performance

Every filter compiles to SQL; nothing is filtered in PHP. The v1 migrations add tenant-leading indexes for
the default order (`created_at`), an agent's and a team's queue, channels, categories and resolution dates,
and the pagination tie-breaks on the primary key so pages are stable. Prefer `cursorPaginate()` for deep
lists and infinite scroll: it never scans an `OFFSET`. It needs a sort column that is never NULL, so it refuses the
`due_at` and `resolved_at` sorts — use `paginate()` (or another sort) for those.

## Tenancy

The query starts from the configured ticket model, so the tenant global scope always applies: a listing only
ever returns the current tenant's tickets (plus shared ones when `allow_shared` is on).
