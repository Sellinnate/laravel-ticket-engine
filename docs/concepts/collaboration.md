---
title: "Collaboration"
description: "Attachments, mentions, canned responses, macros, merge/split and tags."
type: concept
---

# Collaboration

The day-to-day tools agents need on a ticket.

## Attachments

Polymorphic attachments on tickets and messages, on any configurable Laravel disk (local, S3-compatible).
Mime/size validation, checksums, an optional antivirus-scan hook, and signed URLs for download. Inbound
email attachments are imported automatically.

```php
Ticketing::addAttachment($ticket, $uploadedFile, uploadedBy: $agent);
```

## Mentions

`@mention` a teammate in a message to add them as a participant and notify them. Resolution of a mention to a
model is delegated to a `MentionResolver` contract (bind your own; a null resolver is the default).

## Canned responses & macros

- **Canned responses** — reusable reply templates with placeholders (`{{ticket.reference}}`,
  `{{requester.name}}`), per tenant, optionally scoped to a type/category.
- **Macros** — one action that applies a *set* of operations (transition + assignment + reply + tag) to
  standardise repetitive flows ("Escalation L2", "Close for inactivity").

```php
Ticketing::applyMacro($ticket, $macro, actor: $agent);
```

## Merge & split

- **Merge** unifies duplicate tickets — moves messages into the destination, records the merge in the audit,
  and closes the sources with a reference redirect.
- **Split** extracts one or more messages into a new ticket while keeping the link.

Both are testable Actions emitting `TicketMerged` / `TicketSplit`.

## Tags

Free-form, per-tenant tags attachable to tickets for filtering and reporting.
