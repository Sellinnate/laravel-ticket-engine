---
title: "Facade & actions"
description: "The Ticketing facade methods and their backing Action classes."
type: reference
---

# Facade & actions

`Selli\Ticketing\Facades\Ticketing` is the common-path API. Every method delegates to an injectable Action
class you can resolve and unit-test directly.

## Tickets

```php
Ticketing::open(type, title, requester?, priority?, subject?, category?, attributes?, channel?): Ticket
Ticketing::for($subject): PendingTicket        // ->open(...), ->postMessage(...)
Ticketing::tickets(): TicketQuery              // filterable, tenant-scoped listing
Ticketing::transition(ticket, transition, actor?, note?, params?): Ticket
Ticketing::changePriority(ticket, Priority, actor?): Ticket
Ticketing::assign(ticket, assignee?, team?, strategy?, actor?): Ticket
```

## Messages & attachments

```php
Ticketing::postMessage(ticket, author, body, visibility?, meta?): TicketMessage
Ticketing::addAttachment(attachable, UploadedFile, disk?, uploadedBy?): TicketAttachment
```

## Collaboration

```php
Ticketing::tag(ticket, names): Ticket;  Ticketing::untag(ticket, names): Ticket
Ticketing::applyMacro(ticket, macro, actor?): Ticket
Ticketing::merge(target, sources, actor?): Ticket
Ticketing::split(source, messageIds, title?, actor?): Ticket
```

## CSAT

```php
Ticketing::requestCsat(ticket, actor?): SatisfactionRating
Ticketing::csatToken(ticket, ttl?): string
Ticketing::submitCsat(ticket, rating, comment?, submittedBy?): SatisfactionRating
Ticketing::submitCsatByToken(token, rating, comment?, submittedBy?): SatisfactionRating
```

## Channels & GDPR

```php
Ticketing::receiveEmail(InboundEmail): ?TicketMessage
Ticketing::replyAddressFor(ticket): ?string
Ticketing::anonymiseRequester($requester): int
Ticketing::exportRequesterData($requester): array
```

## Model binding

Swap any model the engine uses, at runtime or via `config('ticketing.models')` — every bindable model has a
typed `use*Model()` setter:

```php
Ticketing::useTicketModel(\App\Models\Ticket::class);
Ticketing::ticketModel(); // the configured class string
```
