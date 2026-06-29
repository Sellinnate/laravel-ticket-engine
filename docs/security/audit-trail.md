---
title: "Audit trail"
description: "An append-only, immutable record of every meaningful change."
type: concept
---

# Audit trail

`ticket_activities` is an **append-only** log: every transition, assignment, field change, merge, and access
to sensitive data is recorded with the actor, before/after values and context.

## Immutable by construction

No route in the package updates or deletes an activity — and the model enforces it: an attempt to modify or
delete an activity at runtime throws `ImmutableAuditException`. This is what makes the package usable in
contexts that require traceability (regulated SMEs, audited service contracts).

```php
$activity->update([...]);  // throws ImmutableAuditException
$activity->delete();       // throws ImmutableAuditException
```

The only sanctioned way to add to the trail is through the engine's actions, which write via the
`AuditLogger`.

## The one exception: erasure

[GDPR](/security/gdpr) erasure/retention is the single legitimate reason to remove an activity — and it
happens only through the retention sweep's deliberate raw delete, never through normal application flow.

## Reading it

```php
$ticket->activities()->latest()->get();
// each: event, actor (poly), changes (before/after), context, created_at
```
