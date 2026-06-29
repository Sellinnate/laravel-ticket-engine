---
title: "Automation"
description: "A data-driven trigger → conditions → actions rule engine, plus outbound webhooks."
type: concept
---

# Automation

A lightweight, **data-driven** rule engine runs on top of the domain events.

## Rules

A rule is composed of:

- a **trigger** — an event (or a time-based condition);
- **conditions** — predicates on the ticket;
- **actions** — transition, assignment, tag, reply, notification, webhook, or apply-macro.

It covers cases like *"when an urgent ticket stays unassigned for 10 minutes, notify the team lead"* or
*"when the requester replies to a resolved ticket, reopen it."* Rules are per-tenant, versionable and
testable — and deliberately simple (it is not a BPM). A re-entrancy depth guard stops a rule from triggering
itself in a loop, and a failing rule is isolated (reported, never half-completing a ticket operation).

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
