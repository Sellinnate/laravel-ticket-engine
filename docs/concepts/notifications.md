---
title: "Notifications"
description: "Multi-channel notifications (mail, database, broadcast, Slack) with per-user preferences."
type: concept
---

# Notifications

Notifications use Laravel's native system, across four channels: **mail**, **database** (the in-app bell),
**broadcast**, and **Slack/Teams** (via incoming webhook).

Each event (assignment, new reply, mention, SLA threshold/breach, escalation) is a dedicated notification
class with a configurable `via()`. Channel selection is delegated to a `NotificationPreferences` contract,
resolved per user and per tenant, with sensible defaults — bind your own to honour user preferences.

## Opt-in by design

No channels are active until you set them, so a package upgrade never starts mailing your users. Activate by
listing channels globally and/or per event:

```php
'notifications' => [
    'enabled' => true,
    'preferences' => \Selli\Ticketing\Notifications\ConfigNotificationPreferences::class,

    'default_channels' => ['database'],
    'events' => [
        'sla.breached' => ['mail', 'database', 'slack'],
    ],

    'throttle' => ['seconds' => 300, 'channels' => ['mail', 'slack']], // digest noisy channels
    'slack' => ['webhook' => null, 'timeout' => 5],
],
```

The Slack channel reuses the same SSRF-guarded outbound HTTP as [webhooks](/concepts/automation). Noisy
channels are digested — at most one notification per ticket per throttle window.

Outbound mail carries a tagged `Reply-To` so a recipient's reply threads straight back to the ticket — see
[Email-to-ticket](/channels/email).
