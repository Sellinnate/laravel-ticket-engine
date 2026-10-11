---
title: "GDPR & retention"
description: "Requester anonymisation, data-subject export and configurable retention pruning."
type: concept
---

# GDPR & retention

The package keeps tickets for statistics but can scrub a requester's personal data on request, export their
data, and apply retention automatically.

## Anonymisation

```php
$count = Ticketing::anonymiseRequester($requester);
```

Scrubs the personal data the package *denormalises* (the email channel's `from`/`from-name` on the
requester's own messages), keeps the ticket, audits each of the person's tickets (`requester.anonymized` — a durable marker that keeps them out of later copies such as a search index; a ticket that receives their messages through a merge or split inherits it as `ticket.erasure_inherited`, which outlives the deletion of the marked ticket), and emits `RequesterAnonymized` so
your app can anonymise its own requester model. It spans every tenant the person appears in and reaches
soft-deleted rows, so no PII survives in trash. It also clears the short question excerpts the AI keeps for
the knowledge-gap report.

When the requester is a package [contact](/concepts/contacts), the contact itself is erased too (name, email,
phone, metadata), as are the visitor messages of its widget conversations that never became a ticket, and
`ContactAnonymized` is dispatched. `Ticketing::contacts()->export($contact)` adds the widget conversations to
the export.

## Data-subject export

```php
$data = Ticketing::exportRequesterData($requester);
```

Returns the requester's tickets, their **public** conversation (internal agent notes are excluded — not the
requester's data) and any satisfaction rating. Scoped to tickets where the person is the requester or
subject — never tickets where they were merely an assignee.

## Retention

`php artisan ticketing:prune` applies the configured rules; each **anonymises** or **deletes** closed
tickets of a type (or `*`) older than N days:

```php
'gdpr' => [
    'anonymized_label' => '[anonymized]',
    'retention' => [
        ['type' => 'support', 'after_days' => 730, 'action' => 'anonymize'],
        ['type' => '*',       'after_days' => 1825, 'action' => 'delete'],
    ],
],
```

`delete` is a sanctioned erasure that removes the ticket and **every row it owns** — messages, participants,
the immutable audit, SLA clocks, links, polymorphic attachments, tag pivots, AI turns, article links and the
widget conversation it came from — so nothing is left orphaned, then dispatches `TicketErased` (ids only) for
integrations holding copies. Anonymisation also clears the AI question excerpts. Schedule the command daily.
