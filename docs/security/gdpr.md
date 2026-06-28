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
requester's own messages), keeps the ticket, audits each touched ticket, and emits `RequesterAnonymized` so
your app can anonymise its own requester model. It spans every tenant the person appears in and reaches
soft-deleted rows, so no PII survives in trash.

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
the immutable audit, SLA clocks, links, polymorphic attachments and tag pivots — so nothing is left orphaned.
Schedule the command daily.
