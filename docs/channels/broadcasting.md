---
title: "Broadcasting"
description: "Live ticket updates over Reverb/Echo on private, tenant-scoped channels."
type: guide
---

# Broadcasting

Live page updates (new message, status change, reassignment) over Laravel Echo/Reverb. **Opt-in**: nothing
broadcasts until enabled and your app has a broadcaster configured.

```php
'broadcasting' => [
    'enabled' => false,
    'channel_prefix' => 'ticketing',
    'register_channels' => true,
],
```

## Minimal payloads, private channels

A single queued event, `TicketBroadcasted`, carries only **ids + the delta** (status, the message id, …) — a
subscribed client reloads the detail through the [REST API](/channels/rest-api). It's dispatched by a
subscriber off the domain events, so the domain events stay broadcast-free and the feature stays selective.

Four private, tenant-scoped channels (under the configurable prefix):

| Channel | Audience |
| --- | --- |
| `ticketing.tenant.{id}.tickets` | the tenant-wide agent feed |
| `ticketing.tenant.{id}.agent.{type}.{id}` | an agent's personal feed |
| `ticketing.ticket.{id}` | a single ticket's watchers (public events) |
| `ticketing.ticket.{id}.agents` | a single ticket's agent-only feed (internal notes, assignment) |

## Fail-closed authorization

A bound `ChannelAuthorizer` authorizes every subscription. The default is tenant-scoped and fails closed:
the tenant and agent feeds are agents-only (and the agent feed is *that* agent only, matched by morph type +
id); a ticket channel is limited to the ticket's tenant agents, its requester/subject, or an explicit
participant, loaded through the tenant scope so a cross-tenant id is never authorized. Internal notes go to
the agent feeds only — never a channel a requester may watch — and no message body is ever broadcast.

Bind your own to delegate to your policies:

```php
$this->app->bind(\Selli\Ticketing\Contracts\ChannelAuthorizer::class, MyChannelAuthorizer::class);
```
