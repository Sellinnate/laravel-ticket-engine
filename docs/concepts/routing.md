---
title: "Routing & assignment"
description: "Teams, pluggable assignment strategies and data-driven routing rules."
type: concept
---

# Routing & assignment

## Teams & strategies

Assign a ticket to an agent directly, or to a **team** via an assignment **strategy**:

```php
Ticketing::assign(ticket: $ticket, assignee: $agent);                       // manual
Ticketing::assign(ticket: $ticket, team: $team, strategy: 'round-robin');   // pick within the team
```

Built-in strategies:

| Strategy | Picks |
| --- | --- |
| `manual` | no automatic assignee (human triage) |
| `round-robin` | the next team member in rotation |
| `least-busy` | the member with the fewest open tickets |
| `skill-based` | a member whose skills match the ticket |

Register your own without touching the core:

```php
app(\Selli\Ticketing\Routing\AssignmentManager::class)
    ->extend('priority-weighted', fn ($container) => new PriorityWeightedStrategy(...));
```

Custom strategy names are automatically accepted by the REST API's assignment endpoint.

## Routing rules

On open (and optionally on update), an ordered set of **routing rules** evaluates conditions on the ticket
(type, category, priority, subject, custom fields, sender email, tenant) and routes the first match to a
team/agent/strategy. Conditions are versionable **data**, not `if`s in code.
