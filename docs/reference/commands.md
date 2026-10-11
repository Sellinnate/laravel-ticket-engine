---
title: "Artisan commands"
description: "The package's console commands."
type: reference
---

# Artisan commands

| Command | Purpose |
| --- | --- |
| `ticketing:install` | Publish the config + migrations and offer to run them — the install on-ramp. |
| `ticketing:demo` | Seed one working example ticket (a reply + an internal note). `--type=<key>` to pick the type. |
| `ticketing:escalate` | Sweep SLA clocks and emit `SlaThresholdReached` / `SlaBreached`. **Schedule it** (e.g. every minute). `--threshold=<n>`, `--chunk=<n>`. |
| `ticketing:recalculate-sla` | Recompute SLA clocks after a policy/calendar change. |
| `ticketing:prune` | Apply the [GDPR retention](/security/gdpr) rules (anonymise/delete old closed tickets). **Schedule it** daily. |
| `ticketing:rag:index` | (Re)build and repair the [RAG index](/integrations/rag-engine): published articles, resolved or closed tickets and active canned responses are indexed, anything no longer eligible (or whose source is gone) is removed. `--articles`, `--tickets`, `--canned`. |
| `ticketing:mail:poll` | Ingest new email from the [IMAP mailboxes](/channels/email#imap). `[mailbox…]` (default: all), `--limit=<n>`. **Schedule it** every minute, without overlapping. |

## Scheduling

```php
// routes/console.php or a scheduler
Schedule::command('ticketing:escalate')->everyMinute();
Schedule::command('ticketing:prune')->daily();
Schedule::command('ticketing:mail:poll')->everyMinute()->withoutOverlapping(); // IMAP mailboxes only
```

## Queue worker

Side effects (notifications, webhooks, broadcasts) run on the queue — run a worker:

```bash
php artisan queue:work
```
