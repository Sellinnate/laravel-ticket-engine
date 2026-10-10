---
title: "Contacts"
description: "External requesters with no account in your app — email senders and widget visitors — de-duplicated per tenant."
type: concept
---

# Contacts

Not every requester is a user of your app. Someone who writes to `support@acme.example`, or a visitor who
leaves a name and an email in a website chat, has no row in your `users` table. The package stores them as
**contacts**.

::: callout info "In plain words"
A contact is a lightweight "person we only know by email". The same email in the same tenant is always the
same contact, so all of Jane's emails and chats end up on Jane — and when Jane later signs up, you can link
the contact to her user.
:::

## Remembering a contact

```php
use Selli\Ticketing\Facades\Ticketing;

$contact = Ticketing::contacts()->remember('Jane.Doe@Example.com', 'Jane Doe');

Ticketing::open(type: 'support', title: 'Order missing', requester: $contact);
```

- The email is normalised (trimmed, lower-cased) and validated; an invalid address throws.
- `remember()` is **race-safe**: two simultaneous first emails from the same sender create one contact
  (a unique `dedupe_key` per tenant and email, written with `createOrFirst`).
- A missing name, phone or locale is filled in later; an existing value is **never overwritten** by a later
  (possibly spoofed) one.
- Each tenant has its own contacts; shared (null-tenant) contacts are de-duplicated too.

| Method | Does |
| --- | --- |
| `remember($email, $name = null, $attributes = [])` | find or create (attributes: `phone`, `locale`, `meta`) |
| `find($email)` | the contact in the current tenant, or `null` |
| `markVerified($contact)` | stamp `verified_at` (e.g. after a signed widget identity) |
| `anonymize($contact)` | GDPR erasure (see below) |
| `export($contact)` | GDPR export: identity, tickets, widget conversations |

## Contacts are requesters

`Contact` implements `CanRequestTickets` and uses `Notifiable`, routing mail to its address. Use it anywhere a
requester or participant model fits: notifications reach it by email, the requester listing and the GDPR
export find its tickets, and `user_type`/`user_id` can link it to the host model it later became.

## Where contacts come from

- **Email-to-ticket** resolves the sender to a contact by default (`ticketing.contacts.inbound_email`).
  Bind your own `InboundRequesterResolver` to map senders to your users instead.
- **Widget conversations** create a *verified* contact for a visitor with a valid signed identity, and an
  unverified one from the details a visitor types in the hand-over form.

## Erasure and export

`Ticketing::contacts()->anonymize($contact)` — or `Ticketing::anonymiseRequester($contact)` — keeps the
tickets for statistics and scrubs:

- the contact's name, email, phone and metadata (`anonymized_at` is stamped, the dedupe key cleared so the
  address is free again);
- the email channel's denormalised `from` / `from_name` on its messages;
- the AI question excerpts stored for the knowledge-gap report;
- the visitor messages of its widget conversations that never became a ticket. A conversation that did
  is part of that ticket: its transcript is kept like the ticket's other messages (retention governs it),
  and only its traces — IP hash, origin, typed-in claims — are dropped.

`ContactAnonymized` is dispatched after commit, so integrations holding copies (the RAG index) purge them.

::: callout warning "Unverified identities"
A contact created from a typed-in email is only a claim — anyone can type anyone's address, exactly as anyone
can spoof an email `From`. Treat `verified_at === null` contacts accordingly before sharing account details.
:::
