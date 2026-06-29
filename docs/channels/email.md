---
title: "Email-to-ticket"
description: "Inbound email becomes tickets and replies; outbound mail threads back via a tagged Reply-To."
type: guide
---

# Email-to-ticket

Turn inbound email into tickets and replies, and make outbound mail thread straight back.

## Provider-agnostic inbound

The package never parses a provider webhook itself. A host (or the optional **beyondcode/laravel-mailbox**
bridge) normalises the provider payload into an `InboundEmail` and hands it to the engine:

```php
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Mail\InboundEmail;

Ticketing::receiveEmail(InboundEmail::fromArray([
    'from' => 'jane@example.com',
    'recipients' => ['support@example.com'],
    'subject' => 'Help',
    'text' => 'My printer is broken.',
    'message_id' => '<abc@example.com>',
    'in_reply_to' => null,
    'headers' => ['Auto-Submitted' => 'no'],
]));
```

With `beyondcode/laravel-mailbox` installed, wire it once:

```php
Mailbox::catchAll(fn ($email) => Ticketing::receiveEmail(InboundEmail::fromArray([...])));
```

## Routing

A recipient address resolves to a tenant + ticket type via `routes` (first match wins; `match` is an exact
address, `*`, or `/regex/`):

```php
'mail' => [
    'inbound' => [
        'enabled' => true,
        'routes' => [
            ['match' => 'support@example.com', 'tenant' => 5, 'type' => 'support'],
            ['match' => '*', 'type' => 'support'],
        ],
        'default_type' => 'support',
        'rate_limit' => ['max_per_minute' => 30],
    ],
],
```

An unroutable recipient is dropped (fail closed).

## Threading

Layered, robust:

1. **The tagged reply address** — `support+t_<token>@example.com`. The token is a compact HMAC mapping back
   to the ticket. This is the primary, secure method.
2. **`In-Reply-To` / `References`** against stored `Message-ID`s — a fallback, and only when the sender
   already appears on the ticket (so a replayed Message-ID can't inject into someone else's thread).

## Loop- and duplicate-safe

- Auto-replies, bulk and list mail are dropped (`Auto-Submitted`, `Precedence`, `List-Id`, …).
- A flooding sender is rate limited (counted only for messages actually ingested).
- The **same Message-ID is never ingested twice** — a unique idempotency claim makes the claim, the ticket
  and the message one atomic transaction, so a redelivery is a no-op and a failure leaves nothing behind.

## Outbound

```php
$replyTo = Ticketing::replyAddressFor($ticket); // support+t_<token>@example.com
```

Set `mail.outbound.reply_to_base` and the package's ticket notifications automatically tag their `Reply-To`,
so a recipient's reply threads back to the ticket.
