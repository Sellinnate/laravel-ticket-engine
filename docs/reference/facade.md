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
Ticketing::open(type, title, requester?, priority?, subject?, category?, attributes?): Ticket
Ticketing::for($subject): PendingTicket        // ->open(...), ->postMessage(...)
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
Ticketing::applyMacro(ticket, macro, actor?): Ticket
Ticketing::merge(...); Ticketing::split(...);
```

## CSAT

```php
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

Swap any model the engine uses, at runtime or via `config('ticketing.models')`:

```php
Ticketing::useTicketModel(\App\Models\Ticket::class);
Ticketing::ticketModel(); // the configured class string
```
