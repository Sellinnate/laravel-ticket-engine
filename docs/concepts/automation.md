---
title: "Automation"
description: "A data-driven trigger → conditions → actions rule engine, plus outbound webhooks."
type: concept
---

# Automation

A lightweight, **data-driven** rule engine runs on top of the domain events.

## Rules

A rule (`AutomationRule`, per tenant, ordered by `priority`) is composed of:

- a **trigger** — the domain event it reacts to;
- **conditions** — predicates on the ticket, combined with `match = all` (AND) or `any` (OR);
- **actions** — what to do, in order. `stop_processing` halts the remaining rules once this one matches.

```php
AutomationRule::create([
    'name' => 'Urgent billing to the billing team',
    'event' => 'ticket.opened',
    'conditions' => [
        ['field' => 'category', 'operator' => '=', 'value' => 'billing'],
        ['field' => 'priority_name', 'operator' => 'in', 'value' => ['high', 'urgent']],
    ],
    'actions' => [
        ['type' => 'assign', 'team_id' => 3, 'strategy' => 'least-busy'],
        ['type' => 'tag', 'tags' => ['billing']],
    ],
]);
```

| Triggers | |
| --- | --- |
| Lifecycle | `ticket.opened`, `ticket.assigned`, `ticket.transitioned`, `ticket.resolved`, `ticket.closed`, `ticket.reopened`, `ticket.priority_changed` |
| Conversation | `message.posted`, `participant.added` |
| SLA & CSAT | `sla.threshold_reached`, `sla.breached`, `csat.submitted` |
| AI | `ticket.escalated`, `ticket.taken_over` |

| Condition fields | `priority`, `priority_name`, `status`, `category`, `type`, `team_id`, `assignee_id`, `assignee_type`, `reopened_count`, `is_assigned`, `channel`, `language`, `sentiment`, `ai_status`, `escalation_reason` |
| --- | --- |
| **Operators** | `=`/`eq`, `!=`/`neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `is_null`, `is_not_null`, `contains` |

| Action | Parameters |
| --- | --- |
| `transition` | `transition`, `note?` |
| `assign` | `team_id`, `strategy?` |
| `tag` | `tags` |
| `reply` | `body`, `visibility?` (`public` / `internal`) |
| `priority` | `value` (weight, numeric string or name) |
| `apply_macro` | `macro_id` |
| `webhook` | `url`, `secret?` |

An unknown field, operator or action type fails closed (the rule is reported and skipped). Rules are
deliberately simple (this is not a BPM): a re-entrancy depth guard (`automation.max_depth`) stops a rule
from triggering itself in a loop, and a failing rule is isolated — reported, never half-completing the
operation that emitted the event. Rules react to events; time-based checks belong to the scheduled SLA sweep
(`ticketing:escalate`), whose `sla.threshold_reached` / `sla.breached` events are triggers too.

## Outbound webhooks

A `webhook` action (or a direct webhook delivery) posts a signed payload to an external URL:

- **HMAC signature** over the body so the receiver can verify authenticity.
- An **SSRF guard** (`Support\WebhookGuard`) that validates scheme/host against an allow-list, rejects
  private/reserved IP ranges (including IPv4-mapped IPv6), fails closed on empty DNS, and pins the resolved
  IP for the request to defeat DNS-rebinding.

A dead-lettered delivery emits `WebhookFailed`.

## Configuration

```php
'automation' => ['enabled' => true],
'webhooks' => [
    'block_private' => true,
    'allowed_hosts' => [],   // empty = any public host
],
```
