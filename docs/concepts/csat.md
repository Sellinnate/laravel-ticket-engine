---
title: "CSAT"
description: "Customer satisfaction ratings with stateless, signed rating links."
type: concept
---

# CSAT

Collect a satisfaction rating when a ticket is resolved.

## Auto-request

With CSAT enabled and `auto_request` on, resolving a ticket emits `CsatRequested` — turn that into an email
or notification in your app. A `SatisfactionRating` row is created (unrated) per ticket.

## Signed rating links

Hand a requester a rating link without storing per-request state. A signed, URL-safe token binds the ticket
id (and a per-cycle nonce) with an HMAC over a package secret:

```php
$token = Ticketing::csatToken($ticket);
// e.g. https://app.example.com/csat/{$ticket->getKey()}?token={$token}
```

Submitting:

```php
Ticketing::submitCsatByToken($token, rating: 5, comment: 'Great service');
// or, server-side with an authenticated user:
Ticketing::submitCsat($ticket, rating: 5, comment: 'Great service', submittedBy: $user);
```

A stale-cycle or tampered token is rejected; the REST API surfaces these as a 422.

## Configuration

```php
'csat' => [
    'enabled' => true,
    'auto_request' => true,
    'scale' => ['min' => 1, 'max' => 5],
    'token' => ['secret' => null, 'ttl' => 1209600], // 14 days; falls back to app key
],
```
