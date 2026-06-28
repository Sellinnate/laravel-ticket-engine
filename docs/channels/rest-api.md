---
title: "REST API"
description: "An opt-in, versioned JSON API over the same domain operations, protected by your auth and the package policies."
type: guide
---

# REST API

An **opt-in**, versioned JSON API over the same domain operations. Disabled by default; protect it with
*your* app's auth (Sanctum/Passport) via middleware.

## Enable it

```php
'api' => [
    'enabled' => false,
    'prefix' => 'ticketing/api',
    'version' => 'v1',
    'middleware' => ['api', 'auth'],
    'throttle' => '120,1', // requests, minutes
],
```

Routes mount under `{prefix}/{version}`. You can also publish `routes/api.php` (the `ticketing-routes` tag)
and wire it yourself.

## Endpoints

| Method & path | Action |
| --- | --- |
| `GET /tickets` | list (scoped to the caller — agents see the tenant, requesters see their own) |
| `POST /tickets` | open a ticket |
| `GET /tickets/{ticket}` | show (public messages only) |
| `POST /tickets/{ticket}/messages` | post a reply (internal notes are agent-only) |
| `POST /tickets/{ticket}/transitions` | run a workflow transition |
| `POST /tickets/{ticket}/assignment` | assign to a team/agent or self |
| `POST /tickets/{ticket}/attachments` | upload an attachment |
| `POST /tickets/{ticket}/csat` | submit a satisfaction rating |

## Behaviour

- **Tenant-scoped binding.** `{ticket}` resolves through the configured model under the tenant scope, so a
  cross-tenant id returns 404 — never a leak.
- **Authorization.** Every action runs through the [TicketPolicy](/security/authorization): a stranger gets
  403, a requester is limited to their own tickets, internal notes and state changes are agent-only.
- **Clean errors.** Caller-correctable domain errors (unknown type, disallowed transition, rejected
  attachment, CSAT rejection) become a 422; everything is validated up front via Form Requests.
- **Namespaced routes.** Route names are prefixed `ticketing.` so they can't collide with your app's.
