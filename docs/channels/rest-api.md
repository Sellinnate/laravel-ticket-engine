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

## Listing and filtering {#listing}

`GET /tickets` accepts the [TicketQuery](/concepts/querying) filters as query parameters, plus `per_page`
(1–100, default 25):

```bash
GET /ticketing/api/v1/tickets?state=open&assignee=me&priority=30,40&tag=vip&q=printer&sort=-created_at
```

| Parameter | Example | Meaning |
| --- | --- | --- |
| `state` | `open` | `open`, `closed` or `paused`, per each type's workflow |
| `status` | `new,triaged` | Raw workflow states |
| `priority` | `30,40` | Priority weights |
| `type`, `category`, `channel`, `tag` | `incident` | Any-of filters (comma list or `key[]=…`) |
| `assignee` | `me`, `none`, `App\Models\User:5` | Current assignee |
| `team` | `3` | Owning team |
| `created_from`, `created_to` | `2026-01-01` | Creation date range |
| `q` | `printer` | Exact reference or title substring |
| `sort` | `-priority` | `created_at`, `updated_at`, `priority`, `due_at`, `resolved_at`, `reference`; `-` for descending |

Malformed values never widen the result (a nested array is dropped, `assignee=me` for an anonymous caller
matches nothing), and a requester still only sees their own tickets.

## Behaviour

- **Tenant-scoped binding.** `{ticket}` resolves through the configured model under the tenant scope, so a
  cross-tenant id returns 404 — never a leak.
- **Authorization.** Every action runs through the [TicketPolicy](/security/authorization): a stranger gets
  403, a requester is limited to their own tickets, internal notes and state changes are agent-only.
- **Clean errors.** Caller-correctable domain errors (unknown type, disallowed transition, rejected
  attachment, CSAT rejection) become a 422; everything is validated up front via Form Requests.
- **Namespaced routes.** Route names are prefixed `ticketing.` so they can't collide with your app's.
