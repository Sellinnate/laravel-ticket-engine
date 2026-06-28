---
title: "SLA & escalation"
description: "First-response, next-response and resolution targets on business hours, with a pausing clock."
type: concept
---

# SLA & escalation

An SLA **policy** ties time targets to a `TicketType` + `priority` combination (with a catch-all fallback).
Three independent targets:

- **First response** — time to the first public agent reply.
- **Next response** — time between replies while waiting on the team.
- **Resolution** — time to resolved.

Each target has its own threshold (in minutes) and its own **business-hours calendar**.

## A pausing clock

The SLA clock **pauses** when a ticket enters a "waiting on the customer" state (configurable
`pause_in_states`) and resumes on the next reply — so the team is never penalised for time outside its
control. Clock state (computed `due_at`, elapsed time, any breach) lives in `sla_clocks`.

## Business hours & holidays

Calendars and holidays live in the database (`business_hours`, `holidays`), seeded per tenant. A target's
elapsed time only counts working time.

## Driving escalation

Schedule the sweep (e.g. every minute) to emit threshold and breach events:

```bash
php artisan ticketing:escalate
```

It emits `SlaThresholdReached` and `SlaBreached`, which your [automation](/concepts/automation) rules or
[notifications](/concepts/notifications) react to. Recompute clocks after a config change with
`php artisan ticketing:recalculate-sla`.

## Configuration

```php
'sla' => [
    'enabled' => true,
    'default_threshold_percent' => 75,
],
```
