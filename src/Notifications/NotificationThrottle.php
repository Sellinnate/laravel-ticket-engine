<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications;

use Illuminate\Support\Facades\Cache;

/**
 * Digest/anti-noise throttle: drops a channel for a (recipient, ticket,
 * notification) within a short window so an agent isn't buried under repeated
 * mail/slack for the same ticket. In-app channels (database/broadcast) are not
 * throttled by default — the bell should always reflect reality.
 */
class NotificationThrottle
{
    /**
     * Return the subset of channels not currently throttled, recording a marker
     * for each throttle-eligible channel it lets through.
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    public static function filter(object $notifiable, string $notification, array $channels, int|string $ticketId): array
    {
        $seconds = (int) config('ticketing.notifications.throttle.seconds', 0);

        if ($seconds <= 0) {
            return $channels;
        }

        /** @var list<string> $throttled */
        $throttled = (array) config('ticketing.notifications.throttle.channels', ['mail', 'slack']);

        $passed = [];

        foreach ($channels as $channel) {
            if (! in_array($channel, $throttled, true)) {
                $passed[] = $channel;

                continue;
            }

            $key = self::key($notifiable, $notification, $channel, $ticketId);

            if (Cache::has($key)) {
                continue; // recently sent on this channel — suppress (digest)
            }

            Cache::put($key, true, $seconds);
            $passed[] = $channel;
        }

        return $passed;
    }

    protected static function key(object $notifiable, string $notification, string $channel, int|string $ticketId): string
    {
        $recipient = method_exists($notifiable, 'getKey') ? (string) $notifiable->getKey() : spl_object_hash($notifiable);

        return 'ticketing:notif:'.implode(':', [$notification, $channel, $ticketId, $notifiable::class, $recipient]);
    }
}
