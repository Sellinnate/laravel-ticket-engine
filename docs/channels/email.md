---
title: "Email-to-ticket"
description: "Inbound email becomes tickets and replies — by webhook or IMAP polling — and outbound mail threads straight back."
type: guide
---

# Email-to-ticket

Turn inbound email into tickets and replies, and make outbound mail thread straight back into them.

::: callout info "In plain words"
A customer writes to `support@…`: a ticket opens. Your agent answers from your app: the customer gets an
email, replies from their mail client, and the reply lands on the same ticket — without the quoted history,
and without autoresponders, bounces or your own notifications ever opening tickets.
:::

## Getting mail in

Two ways, both ending in `Ticketing::receiveEmail()`:

### 1. A provider webhook

The package never parses a provider payload itself. Map your provider's webhook (Postmark, Mailgun, SES,
SendGrid…) to an `InboundEmail`:

```php
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Mail\InboundEmail;

Route::post('/webhooks/inbound-mail', function (Request $request) {
    // verify the provider's signature first
    Ticketing::receiveEmail(InboundEmail::fromArray([
        'from' => $request->input('From'),
        'from_name' => $request->input('FromName'),
        'recipients' => [...$to, ...$cc],      // every To/Cc address (routing + reply token)
        'cc' => $cc,                           // Cc alone: they become `cc` participants
        'subject' => $request->input('Subject'),
        'text' => $request->input('TextBody'),
        'html' => $request->input('HtmlBody'),
        'message_id' => $request->input('MessageID'),
        'in_reply_to' => $headers['In-Reply-To'] ?? null,
        'references' => $headers['References'] ?? [],
        'headers' => $headers,                 // for the anti-loop checks
        'attachments' => [['filename' => 'a.pdf', 'mime' => 'application/pdf', 'content' => $bytes]],
    ]));

    return response()->noContent();
});
```

### 2. IMAP polling {#imap}

No webhook? Poll a mailbox. Install the optional IMAP client and configure the mailbox:

```bash
composer require directorytree/imapengine
```

```php
'mail' => [
    'inbound' => [
        'enabled' => true,
        'mailboxes' => [
            'support' => [
                'driver' => 'imap',
                'host' => env('TICKETING_IMAP_HOST'),
                'port' => 993,
                'encryption' => 'ssl',
                'username' => env('TICKETING_IMAP_USERNAME'),
                'password' => env('TICKETING_IMAP_PASSWORD'),
                'folder' => 'INBOX',
                'processed_folder' => 'Processed',   // or null: processed mail is flagged seen
                'failed_folder' => 'Failed',         // or null: set-aside mail is flagged and marked seen
                'max_attempts' => 10,
            ],
        ],
    ],
],
```

```php
// routes/console.php
Schedule::command('ticketing:mail:poll')->everyMinute()->withoutOverlapping();
```

`ticketing:mail:poll [mailbox…] --limit=50` reads the unseen messages, oldest first, ingests each one and only
then moves it (or flags it seen). A message that fails stays unseen and is retried with a growing delay
(1, 2, 4… minutes, at most 6 hours) while newer mail keeps flowing; after `max_attempts` failures it is set
aside (moved to `failed_folder`, or flagged) for a person to look at. A message whose sender is over the rate
limit waits a minute. Automated mail is acknowledged and dropped; a redelivered message is never ingested
twice (Message-ID claim). One unreachable server does not stop the other mailboxes.

Another source (Gmail API, Microsoft Graph…)? Implement `Contracts\InboundMailbox` — acknowledge a message
once the handler returns, keep it when the handler throws or returns `false` — and register a driver:

```php
Ticketing::mailboxes()->extend('graph', fn ($app, array $config) => new GraphMailbox($config));
```

## Routing

A recipient address resolves to a tenant and a ticket type through `routes` (first match wins; `match` is an
exact address, `*`, or a `/regex/`). An unroutable recipient is dropped (fail closed). Bind your own
`InboundMailRouter` to route from a table.

```php
'routes' => [
    ['match' => 'support@acme.example', 'tenant' => 5, 'type' => 'support'],
    ['match' => '*', 'type' => 'support'],
],
```

## Threading {#threading}

An email joins an existing ticket when, in this order:

1. **its recipient carries the reply token** — `support+t_<token>@example.com`, the signed address the
   package puts in the `Reply-To` of its notifications. It authorises threading on its own;
2. **`In-Reply-To` / `References`** name an email of the ticket — a customer email (indexed
   `email_message_id` column) or one of the package's own notifications (signed Message-IDs);
3. **the subject carries the ticket reference** in brackets — `Re: Printer [SUP-2026-00042]` — and exactly
   one such ticket matches (in the routed tenant).

Options 2 and 3 are only accepted from a **sender already on the ticket** (who emailed it before, or a
contact requester/participant), so a replayed Message-ID or a guessed reference can't inject into someone
else's thread. Anything else opens a new ticket.

## What gets stored

- **The reply, not the thread**: the quoted history (`On … wrote:`, Outlook headers, `>` lines — in
  English, Italian, German, French and Spanish), the signature (`-- `) and mobile footers are cut. The
  message as sent stays in `meta.original_body`. Turn it off with `mail.inbound.strip_quotes = false`.
- **Cc'd people** become `cc` participants (as [contacts](/concepts/contacts)) — at most ten per email,
  never your own addresses nor the sender — so they can reply into the thread and receive the public
  replies. Turn it off with `mail.inbound.cc_participants = false`.
- The sender becomes the requester ([contacts](/concepts/contacts)) unless you bind an
  `InboundRequesterResolver`.

## Loop- and duplicate-safe

Never ingested:

- auto-replies, bulk and list mail (`Auto-Submitted`, `Precedence`, `X-Autoreply`, `List-Id`, …);
- bounces and delivery reports (`multipart/report`, `X-Failed-Recipients`, an empty `Return-Path`,
  `mailer-daemon@` / `postmaster@` senders);
- `noreply@` / `no-reply@` / `do-not-reply@` senders;
- the package's own notifications coming back (`X-Ticketing-Mailer` header or one of its signed
  Message-IDs).

A flooding sender is rate limited (counted only for messages actually ingested), and the **same Message-ID
is never ingested twice**: the claim, the ticket and the message commit in one transaction.

## Outbound {#outbound}

```php
'outbound' => [
    'reply_to_base' => 'support@acme.example',   // Reply-To becomes support+t_<token>@acme.example
],
```

Every ticket notification the package mails:

- has the tagged `Reply-To` (with `reply_to_base` set);
- carries a signed `Message-ID`, an `In-Reply-To` / `References` pointing at the customer's latest email
  (or the ticket's thread root), so mail clients show one conversation per ticket;
- is marked `X-Ticketing-Mailer: selli/ticketing`;
- for a new reply: is titled `Re: <ticket title> [<reference>]` and carries the **whole** reply; an AI answer
  is flagged `Auto-Submitted: auto-replied` (RFC 3834), so the customer's autoresponder stays quiet.

The thread token is signed with `mail.token.secret` (falling back to the app key).
