---
title: "Types & workflow"
description: "Config-driven state machines per ticket type, with guards and pluggable workflow drivers."
type: concept
---

# Types & workflow

Each ticket **type** maps to a **workflow** — a set of states and transitions defined as *data*, not code.

## Defining a workflow

```php
'workflow' => [
    'workflows' => [
        'default' => [
            'initial' => 'open',
            'states' => ['open', 'pending', 'resolved', 'closed'],
            'transitions' => [
                'wait'    => ['from' => ['open'], 'to' => 'pending'],
                'resume'  => ['from' => ['pending'], 'to' => 'open'],
                'resolve' => ['from' => ['open', 'pending'], 'to' => 'resolved'],
                'close'   => ['from' => ['resolved', 'open', 'pending'], 'to' => 'closed'],
                'reopen'  => ['from' => ['resolved', 'closed'], 'to' => 'open'],
            ],
            'terminal' => ['closed'],
            'semantics' => [
                'open'   => ['open'],
                'closed' => ['closed', 'resolved'],
                'paused' => ['pending'],
            ],
        ],
    ],
],
```

The config is **validated at boot**: a workflow that references a missing state fails fast. The `semantics`
map lets SLA and reporting reason about "open" vs "paused" vs "closed" regardless of your state names.

## Transitions

```php
Ticketing::transition(ticket: $ticket, transition: 'resolve', actor: $agent, note: 'Fixed.');
```

An unknown or disallowed transition raises a `TicketingException` (`UnknownTransitionException` /
`TransitionNotAllowedException`) — the REST API turns these into a 422.

## Guards

A transition can declare a **guard** class that vetoes it unless a precondition holds — e.g. requiring a
resolution note:

```php
'resolve' => ['from' => ['in_progress'], 'to' => 'resolved', 'guard' => RequireResolutionNote::class],
```

## Custom workflow drivers

The rest of the engine (SLA, routing, events) talks to the `WorkflowDriver` contract, never to the config
driver directly. To resolve states and transitions from somewhere else — a database table, typed state
classes — implement the contract and register it:

```php
use Selli\Ticketing\Workflow\WorkflowManager;

app(WorkflowManager::class)->extend('database', fn ($app) => $app->make(DatabaseWorkflowDriver::class));
```

then set `ticketing.workflow.driver` to `database`.
