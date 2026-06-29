---
title: "Authorization"
description: "Agnostic Laravel policies resolved via the Gate, agent/requester aware."
type: concept
---

# Authorization

The package imposes no permission system. It defines a Laravel **`TicketPolicy`** for the key abilities and
resolves it via the Gate. "Agent" and "requester" come from the package [contracts](/getting-started/concepts);
internal-note visibility is denied to non-agents at the policy *and* query level. Optional integration with
`spatie/laravel-permission` is available, never required.

## Abilities

| Ability | Who |
| --- | --- |
| `viewAny`, `create` | any agent or requester |
| `view`, `comment`, `addAttachment`, `submitCsat` | a tenant agent, or the ticket's requester/subject/participant |
| `viewInternal`, `commentInternal` | tenant agents only |
| `transition`, `assign`, `changePriority`, `merge`, `split`, `delete` | tenant agents only |

The REST API authorizes every action through this policy (a stranger gets 403; a requester is limited to
their own tickets). The facade/Action layer is intentionally unguarded — it is the trusted lower layer;
policies guard the host-facing entry points.

## Registration & override

Registered automatically for the configured ticket model when policies are on:

```php
'authorization' => ['register_policies' => true],
```

Bind your own policy (or turn registration off and register it yourself) to delegate to your permission
system:

```php
use Selli\Ticketing\Support\Ticketing;

// Use the *configured* ticket model, so an override (useTicketModel()) is honoured.
Gate::policy(Ticketing::ticketModel(), \App\Policies\MyTicketPolicy::class);
```

## Tenant isolation is the first barrier

Before any policy runs, the [tenant scope](/concepts/tenancy) fails closed — a cross-tenant ticket is simply
not found. Authorization is defence in depth on top of that.
