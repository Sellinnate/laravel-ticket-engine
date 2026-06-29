---
title: "Events"
description: "The domain events you can listen to — the package's own subsystems are just listeners."
type: reference
---

# Events

Every meaningful change emits an event under `Selli\Ticketing\Events`. The package's own SLA, routing,
notification, automation and broadcasting subsystems are nothing but listeners on these — so anything they do,
you can do too. Domain events implement `ShouldDispatchAfterCommit`, so a listener never sees an uncommitted
ticket.

## Lifecycle

| Event | When |
| --- | --- |
| `TicketOpened` | a ticket is opened |
| `StateTransitioned` | any transition (carries `from`, `to`, `transition`) |
| `TicketResolved` / `TicketClosed` / `TicketReopened` | terminal/reopen transitions |
| `PriorityChanged` | priority changed |
| `TicketAssigned` | assignee and/or team changed |

## Conversation & collaboration

| Event | When |
| --- | --- |
| `MessagePosted` | a message is posted |
| `AttachmentAdded` | an attachment is added |
| `ParticipantAdded` | a participant joins (e.g. via a mention) |
| `TicketMerged` / `TicketSplit` | merge / split |

## SLA & CSAT

| Event | When |
| --- | --- |
| `SlaThresholdReached` / `SlaBreached` | the escalation sweep crosses a threshold / breaches |
| `CsatRequested` / `CsatSubmitted` | a rating is requested / submitted |

## Channels & side effects

| Event | When |
| --- | --- |
| `TicketBroadcasted` | a realtime update (queued, minimal payload) |
| `WebhookFailed` | an outbound webhook is dead-lettered |
| `RequesterAnonymized` | a requester's PII was scrubbed (anonymise the host model here) |

## Listening

```php
Event::listen(\Selli\Ticketing\Events\SlaBreached::class, function ($event) {
    // $event->ticket, ...
});
```

::: callout tip
Side-effect listeners are wrapped so a failure is reported but never propagates back to the action that
emitted the event — a misconfigured rule can't fail (or half-complete) a ticket operation.
:::
