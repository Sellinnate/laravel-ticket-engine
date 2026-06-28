---
title: "Quick Start"
description: "Open, reply to, transition and assign a ticket from code."
type: guide
---

# Quick Start

Everything below uses the `Ticketing` facade. The same operations are available as injectable Action classes
(for fine control and testing) and emit events (for extension).

## Open a ticket

```php
use Selli\Ticketing\Facades\Ticketing;

$ticket = Ticketing::open(
    type: 'support',
    title: 'My printer is broken',
    requester: $user,         // a CanRequestTickets model (or null)
    priority: \Selli\Ticketing\Enums\Priority::High,
    category: 'hardware',
);

echo $ticket->reference; // e.g. SUPPORT-2026-000042
```

Attach the ticket to one of your models (its **subject**):

```php
$ticket = Ticketing::for($order)->open(type: 'support', title: 'Wrong item shipped', requester: $user);
// or, with the HasTickets trait:
$order->openTicket(type: 'support', title: 'Wrong item shipped', requester: $user);
```

## Reply

```php
use Selli\Ticketing\Enums\MessageVisibility;

Ticketing::for($ticket)->postMessage($agent, 'Looking into it now.');
Ticketing::for($ticket)->postMessage($agent, 'Internal: RMA opened.', MessageVisibility::Internal);
```

Public replies reach the requester; internal notes are visible to agents only.

## Move it through the workflow

```php
$ticket = Ticketing::transition(ticket: $ticket, transition: 'resolve', actor: $agent);
```

Transitions are defined per ticket **type** in config — never as `if`s in your code. See
[Types & workflow](/concepts/workflow).

## Assign it

```php
Ticketing::assign(ticket: $ticket, assignee: $agent);            // to an agent
Ticketing::assign(ticket: $ticket, team: $team, strategy: 'least-busy'); // to a team via a strategy
```

## Change priority

```php
use Selli\Ticketing\Enums\Priority;

Ticketing::changePriority($ticket, Priority::Urgent, actor: $agent);
```

## Next

- [Core concepts](/getting-started/concepts)
- Expose it over the [REST API](/channels/rest-api) or take tickets in by [email](/channels/email).
