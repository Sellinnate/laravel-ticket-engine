---
title: "Embed a support widget on an external site"
description: "Step by step: a widget key per customer site, signed identities, CORS, CSP and a minimal chat UI on the reference client."
type: guide
---

# Embed a support widget on an external site

This guide puts a support chat on a website you do not host — say, the site of one of your customers — with
the AI answering from your knowledge base and your agents taking over in the ticket queue.

::: callout info "What you will build"
A `<script>` on `https://shop.acme.example` that opens conversations against your Laravel app, answers from
the articles meant for that customer, and turns into a ticket in Acme's queue when a person is needed. The
browser only ever holds a public key and its own conversation token.
:::

## 1. Enable the widget API

```php
// config/ticketing.php
'widget' => ['enabled' => true, 'middleware' => ['api']],
'ai' => ['enabled' => true, 'driver' => 'your-driver', 'modes' => ['types' => ['support' => 'auto']]],
```

Keep the widget routes out of your `web` (session/CSRF) group: they authenticate with the key and a bearer
token, never cookies. Run a queue worker if `ai.queue` is on.

## 2. Issue a key for the site

```php
['key' => $key, 'secret' => $secret] = Ticketing::widgetKeys()->create(
    name: 'Acme shop',
    allowedOrigins: ['https://shop.acme.example'],
    audience: $acme,              // the customer: its restricted articles become visible here
    ticketType: 'support',
    settings: ['greeting' => 'Hi! Ask us anything about your order.', 'locale' => 'en'],
);
```

Store `$key->public_key` with the customer's configuration. Give `$secret` to the customer's **backend** only
if they want signed identities (step 4); it is shown once and stored encrypted.

## 3. Publish the client and add it to the page

```bash
php artisan vendor:publish --tag=ticketing-widget
```

```html
<script src="https://app.example/vendor/ticketing/ticketing-widget.js" defer></script>
<div id="support-chat"></div>
<script type="module">
  const chat = new TicketingWidget({
    endpoint: 'https://app.example/ticketing/widget/v1',
    key: 'tk_pub_…',
  });

  const settings = await chat.config();
  const root = document.getElementById('support-chat');
  const list = root.appendChild(document.createElement('ol'));
  const form = root.appendChild(document.createElement('form'));
  form.innerHTML = '<input name="body" maxlength="' + settings.max_message_length + '" required>'
    + '<input name="website" tabindex="-1" autocomplete="off" hidden><button>Send</button>';

  const render = (messages) => messages.forEach((m) => {
    const item = list.appendChild(document.createElement('li'));
    item.dataset.role = m.role;
    item.textContent = m.body;            // textContent: never inject HTML from a message
  });

  await chat.start();
  chat.poll(render);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = new FormData(form);
    await chat.send(data.get('body'), { honeypot: data.get('website') });
    form.reset();
  });
</script>
```

Render message bodies with `textContent` (or a sanitising Markdown renderer): they come from visitors, agents
and the AI.

## 4. Optional: signed identity for logged-in shoppers

On the **customer's server**, sign the shopper and pass the result to the page:

```php
$signature = hash_hmac('sha256', (string) $shopper->id, $widgetSecret);
// render: window.__supportIdentity = { user_id, email, name, signature }
```

```js
await chat.start({ identity: window.__supportIdentity });
```

A valid signature makes the conversation verified: tickets belong to the verified contact (or to your own
user model if you bind a `VisitorResolver`) and the AI may use the customer's `customer` articles. Never sign
on the client: whoever holds the secret can impersonate anyone.

## 5. Let visitors ask for a person

```js
await chat.escalate({ name: 'Jane', email: 'jane@example.com' });   // anonymous visitors
await chat.escalate();                                              // verified visitors
```

The conversation becomes a ticket with the transcript; agents' replies then appear in `poll()`. If the AI
hands over by itself, the timeline shows a system message asking for contact details.

## 6. Harden the embedding

- **Origins.** List exact origins; staging and production need separate entries. Never ship `*`.
- **CSP.** On the customer's site allow your app in `connect-src` (and `script-src` if you serve the client).
- **Captcha.** Bind a `HumanVerifier` (Turnstile, hCaptcha) and pass `captchaToken` to `start()` and
  `escalate()` for anonymous visitors.
- **Limits.** Tune `widget.rate_limits` and `max_messages_per_conversation` for the expected traffic.
- **Revocation.** `Ticketing::widgetKeys()->revoke($key)` stops a key at once; `rotateSecret()` invalidates
  every signed identity.
