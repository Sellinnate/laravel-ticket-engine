---
title: "Reporting & KPIs"
description: "Support metrics for a period — volume, backlog, response and resolution times, SLA, reopen rate, AI, CSAT, knowledge gaps — and streamed CSV exports."
type: concept
---

# Reporting & KPIs

`Ticketing::metrics()` answers the questions a support lead asks every week, straight from the database:
nothing is pre-aggregated, no ticket is loaded into memory, and the same numbers come out on SQLite,
MySQL/MariaDB, PostgreSQL and SQL Server.

::: callout info "In plain words"
Pick a period and, if you want, a slice (a channel, a team, a ticket type). You get how many tickets came
in and went out, how many are waiting and for how long, how fast your team answers and solves, how often
the SLA held, how much the AI did and what it cost, what customers thought, and which questions nobody
could answer.
:::

## Quick use

```php
use Selli\Ticketing\Facades\Ticketing;

$metrics = Ticketing::metrics()
    ->between(now()->subDays(30), now())                   // default: the last 30 days
    ->filter(['channel' => 'email', 'team' => 'support']);  // optional, the listing filters

$metrics->summary();          // everything below, at once
$metrics->breakdown('agent'); // per agent | team | channel | category | priority | type
```

Metrics are computed for the **current tenant** (the tenant scope applies) and only for what the caller
narrows them to. `filter()` takes the same keys as the [ticket listing](/concepts/querying) (`channel`,
`type`, `team`, `assignee`, `category`, `priority`, `tag`, `status`…); `where(fn (TicketQuery $q) => …)`
takes any `TicketQuery` call.

## What each metric means {#metrics}

| Method | Returns | Counts |
| --- | --- | --- |
| `volume()` | `created`, `resolved`, `closed`, `per_day` | tickets created / resolved / closed **in the period**; created per day |
| `backlog()` | `open`, `unassigned`, `age` buckets | tickets **not closed right now** (open + paused), by age: < 1 day, 1-3, 3-7, 7-30, > 30 days |
| `firstResponse()` | `count`, `mean_seconds`, `median_seconds` | time to the first agent reply, for the tickets **created** in the period |
| `resolution()` | `count`, `mean_seconds`, `median_seconds` | time from creation to resolution, for the tickets **resolved** in the period |
| `reopenRate()` | `0..1` or `null` | share of the tickets resolved in the period that had been reopened |
| `sla()` | per target: `met`, `breached`, `running`, `compliance` | the [SLA clocks](/concepts/sla) of the tickets created in the period — a deadline missed before the sweep ran (completed late, or still open and overdue) counts as breached; compliance = met / (met + breached) |
| `ai()` | turns by outcome, AI-handled tickets, `resolved_by_ai`, `escalated`, rates, `escalation_reasons`, conversations, tokens, `cost` per currency | the [AI](/concepts/ai) turns of the period, and the tickets of the period the AI actually ran on (or on the widget conversation they came from) — not those an agent took first |
| `csat()` | `requested`, `submitted`, `response_rate`, per scale: average and distribution | the [CSAT](/concepts/csat) surveys sent / answered in the period |
| `knowledgeGaps($limit = 20)` | `question`, `asked`, `last_asked_at` | questions the AI could not answer (no sources, low confidence), grouped by their normalised text, most asked first |
| `breakdown($dimension)` | per key: `label`, `created`, `resolved`, `open`, mean first response and resolution | the same flow metrics, grouped |

- Durations are **wall-clock seconds**. Business hours are what the SLA clocks measure, so compliance is the
  business-hours view and the durations the customer's.
- Medians are read by the database at the middle offset of the ordered values — exact, with no row
  loaded in PHP.
- With a ticket filter, the AI and CSAT figures follow the filtered tickets and the widget conversations
  (which a ticket filter cannot match) are left out (`conversations: null`).
- `breakdown()` labels team and type keys with their names, agents with their `name` (or `email`),
  priorities with their label. Tickets without the dimension come back with `key: null`.

## CSV exports {#csv}

```php
return Ticketing::metrics()->between($from, $to)->exportTickets();      // the tickets created in the period
return Ticketing::metrics()->between($from, $to)->exportBreakdown('team');
```

Both return a `StreamedResponse`: rows are read by key in chunks and written straight to the output, so
an export of any size runs in constant memory. Pick the ticket columns with
`exportTickets(['reference', 'title', 'status', 'created_at'])`. Cells starting with `=`, `+`, `-`, `@`,
a tab or a carriage return are prefixed with a quote, so a spreadsheet never runs them as formulas.

::: callout tip "Why CSV and not Excel?"
XLSX needs a heavy dependency and buffers the file; CSV streams and opens in every spreadsheet tool.
:::

## Over the REST API {#api}

| Method & path | Returns |
| --- | --- |
| `GET /reports/metrics?from=&to=&…filters` | `{data: summary}` |
| `GET /reports/breakdown/{dimension}?from=&to=&format=csv` | the breakdown as JSON, or CSV with `format=csv` |
| `GET /reports/tickets?from=&to=&…filters` | the tickets of the period as CSV |

`from` / `to` are ISO dates (a bare date covers its whole day) or date-times; the default is the last 30
days. Only agents may read reports (the `viewReports` policy ability).

## Performance

Every section is a handful of aggregate queries whatever the volume — the test suite computes the whole
summary of 10 000 tickets in under 45 statements, on every database. The indexes the package adds
(tenant + `created_at`, `resolved_at`, `escalated_at`, AI turns by date and fingerprint) back them.
