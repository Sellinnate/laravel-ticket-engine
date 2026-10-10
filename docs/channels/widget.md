---
title: "Chat & widget conversations"
description: "A public JSON API for chat widgets — on your app or a customer's site — whose conversations become tickets when a human is needed."
type: guide
---

# Chat & widget conversations

An **opt-in public JSON API** for chat widgets, on your own app or embedded on a customer's website. A visitor
talks to the AI in a **conversation**; when a human is needed the conversation **becomes a ticket** carrying
the whole transcript, and continues there. No UI is imposed: a small reference JS client is available.

::: callout info "In plain words"
You give each website a public key. The browser opens a conversation with it, chats with your AI assistant,
and can ask for a person at any time; your agents then answer from the ticket and the visitor sees their
replies in the same chat window. The browser never sees an internal id or anything not meant for it.
:::

## Enable it

```php
'widget' => [
    'enabled' => true,
    'prefix' => 'ticketing/widget',
    'version' => 'v1',
    'middleware' => ['api'],
    'rate_limits' => [
        'per_ip' => '60,1',               // requests, minutes
        'per_key' => '1200,1',
        'conversations_per_ip' => '10,60',
    ],
    'max_message_length' => 4000,
    'max_messages_per_conversation' => 50,
    'honeypot_field' => 'website',
    'token_ttl' => 604800,                // seconds; 0 = no expiry
    'require_email_to_escalate' => true,
    'human_verifier' => \Selli\Ticketing\Support\NullHumanVerifier::class,
],
```

Do **not** put the widget routes behind your session/CSRF middleware: they authenticate with the widget key
and a per-conversation bearer token, and never use cookies.

## Widget keys {#keys}

```php
['key' => $key, 'secret' => $secret] = Ticketing::widgetKeys()->create(
    name: 'Acme website',
    allowedOrigins: ['https://acme.example'],
    audience: $client,            // optional: articles restricted to this client become visible here
    ticketType: 'support',        // the type of tickets this widget opens
    settings: ['greeting' => 'Hi! How can we help?', 'locale' => 'en'],
);

$key->public_key;                 // tk_pub_… — goes into the page
$secret;                          // tk_sec_… — stays on YOUR server, shown once (stored encrypted)

Ticketing::widgetKeys()->rotateSecret($key);
Ticketing::widgetKeys()->revoke($key);
```

- A key belongs to one tenant: every request through it runs as that tenant.
- **Origins** are compared on `scheme://host[:port]`. A browser request from an origin outside the list gets
  `403`; an empty list allows no browser at all; `*` allows any (use only for testing). Requests without an
  `Origin` header (server-to-server) are accepted and stay rate limited.

## Signed visitor identity {#identity}

Anonymous visitors are fine. When the visitor is signed in on the host site, sign their identity **on your
server** with the key's secret and hand it to the widget:

```php
use Selli\Ticketing\Conversations\WidgetKeys;

$signature = WidgetKeys::sign($secret, $user->id);              // or the lower-cased email
// pass { user_id, email, name, signature } to the widget
```

A valid signature makes the conversation **verified**: it is linked to your user (bind a `VisitorResolver`)
or to a verified contact. An unsigned identity is only remembered as a claim and never trusted.

## The API {#api}

Every request sends `X-Ticketing-Key: tk_pub_…`; conversation routes also `Authorization: Bearer <token>`
(returned once by `POST /conversations`). Base path: `/{prefix}/{version}`.

| Method & path | Does |
| --- | --- |
| `GET /config` | name, greeting, locale, whether an assistant answers, limits |
| `POST /conversations` | start: `{identity?, locale?, captcha_token?}` → `{id, token, status, expires_at, …}` |
| `GET /conversations/{id}/messages?since=` | the visitor's timeline (before the hand-over, then the ticket's public replies) |
| `POST /conversations/{id}/messages` | `{body}` — the AI answers while the conversation is open |
| `POST /conversations/{id}/escalate` | ask for a person: `{name?, email?, captcha_token?}` |
| `POST /conversations/{id}/close` | end the conversation |
| `GET /kb/search?q=&locale=&limit=` | help-center search for deflection |
| `GET /kb/articles/{slug}?locale=` | one public article |
| `POST /kb/articles/{slug}/feedback` | `{helpful, comment?}` — one vote per conversation or visitor |

Messages carry an opaque `id`, `role` (`visitor`, `assistant`, `agent`, `system`), `body`, `sources`
(title, type, url) and `at`. `since` returns messages at or after that instant; de-duplicate on `id`.

### Help center {#knowledge-base}

The `kb/*` routes serve the widget's audience — public articles, plus those restricted to the key's audience
scope; identified visitors (send `X-Ticketing-Conversation` and the bearer token) also see customer articles.
Articles are addressed by slug and locale, never by id.

## Conversations becoming tickets {#escalation}

- **AI on (`auto`)**: the AI answers; when a rule fires — or the visitor calls `escalate` — the conversation
  becomes a ticket of the key's type, on channel `widget`, requested by the verified user, the verified
  contact or the email the visitor typed. The transcript is copied without notifying anyone.
- **AI `draft` or `off`**: the first message already opens the ticket (humans answer); in draft mode the AI
  drafts a reply on it.
- **Anonymous visitors** must leave an email first (`require_email_to_escalate`): an AI-triggered hand-over is
  parked (`handoff_pending`), the AI stops answering and a system message asks for contact details.
- Afterwards the visitor's messages go to the ticket, and the agents' public replies show up in the widget.
- Agents can take a conversation over at any time: `Ticketing::conversations()->takeOver($conversation, $agent)`.

Closing a conversation the AI answered without a hand-over marks it `resolved_by_ai`.

## Abuse protection

- Rate limits per IP, per key and for conversation starts.
- A honeypot field (`website` by default) that must arrive empty.
- Message length and per-conversation message caps.
- Bearer tokens stored as SHA-256 hashes, compared in constant time, expiring after `token_ttl`.
- A `HumanVerifier` hook (Turnstile, hCaptcha…) for anonymous visitors on start and hand-over — bind your own.
- IPs are stored only as salted hashes.

## Reference JS client {#client}

A dependency-free client implementing the protocol (no UI) is published with
`php artisan vendor:publish --tag=ticketing-widget` into `public/vendor/ticketing/ticketing-widget.js`:

```html
<script src="/vendor/ticketing/ticketing-widget.js"></script>
<script>
  const chat = new TicketingWidget({ endpoint: 'https://app.example/ticketing/widget/v1', key: 'tk_pub_…' });
  await chat.start({ identity: window.__signedIdentity });   // optional
  await chat.send('How do I reset my password?');
  const stop = chat.poll(messages => render(messages));
  // chat.escalate({ name, email }), chat.close(), chat.searchArticles('reset'), chat.feedback(slug, true)
</script>
```

The conversation id and token live in `sessionStorage` (per tab) unless you pass `storage: null`.

::: callout tip "Why ship a client but no UI?"
The protocol details — token storage, signed identity, polling de-duplication, error handling — are easy to
get subtly wrong and identical for every Selli product, so they ship once and are tested. The look and feel
differs per product, so the package stays headless.
:::
