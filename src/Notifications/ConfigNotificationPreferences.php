<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications;

use Selli\Ticketing\Contracts\NotificationPreferences;

/**
 * The default, config-driven preferences: each notification's channels come from
 * `ticketing.notifications.events.{key}` (falling back to
 * `ticketing.notifications.default_channels`), intersected with what the
 * notification actually supports. A host app can bind a richer per-user
 * implementation instead.
 */
class ConfigNotificationPreferences implements NotificationPreferences
{
    public function channels(object $notifiable, string $notification, array $supported): array
    {
        // Index the events array by the LITERAL key (notification keys contain a
        // dot, which config()'s dot-notation would otherwise treat as nesting).
        $events = (array) config('ticketing.notifications.events', []);

        // Fall back to the (opt-in, empty by default) global channels.
        /** @var list<string> $configured */
        $configured = is_array($events[$notification] ?? null)
            ? $events[$notification]
            : (array) config('ticketing.notifications.default_channels', []);

        // Keep only channels both the preference and the notification support,
        // preserving the notification's declared order.
        return array_values(array_filter($supported, static fn (string $channel): bool => in_array($channel, $configured, true)));
    }
}
