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
        /** @var list<string> $configured */
        $configured = (array) config(
            "ticketing.notifications.events.{$notification}",
            config('ticketing.notifications.default_channels', ['mail', 'database']),
        );

        // Keep only channels both the preference and the notification support,
        // preserving the notification's declared order.
        return array_values(array_filter($supported, static fn (string $channel): bool => in_array($channel, $configured, true)));
    }
}
