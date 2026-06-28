<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Selli\Ticketing\Support\WebhookGuard;

/**
 * A dependency-free Slack/Teams channel: POSTs a `{ "text": ... }` payload to an
 * incoming-webhook URL. The URL comes from the notifiable's
 * routeNotificationFor('slack') or the configured default. The notification
 * supplies the text via a toSlack() method.
 */
class SlackWebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $url = self::webhookUrl($notifiable, $notification);

        if ($url === null) {
            return;
        }

        $text = method_exists($notification, 'toSlack')
            ? (string) $notification->toSlack($notifiable)
            : '';

        if ($text === '') {
            return;
        }

        // Run the URL through the same SSRF guard as outbound webhooks, since a
        // host may wire a per-user Slack route (potentially user-supplied).
        $pinnedIp = WebhookGuard::assertAllowed($url);

        $timeout = max(1, (int) config('ticketing.notifications.slack.timeout', 5));

        $request = Http::timeout($timeout)->asJson()->withoutRedirecting();

        // Surface a 4xx/5xx so a failed delivery retries rather than being
        // silently swallowed.
        WebhookGuard::applyPin($request, $url, $pinnedIp)->post($url, ['text' => $text])->throw();
    }

    /**
     * Resolve the Slack/Teams webhook URL for a recipient (its
     * routeNotificationFor('slack') or the configured default), or null if none
     * is available — also used by the subscriber to avoid spending a digest slot
     * on an undeliverable Slack send.
     */
    public static function webhookUrl(object $notifiable, ?Notification $notification = null): ?string
    {
        if (method_exists($notifiable, 'routeNotificationFor')) {
            $route = $notifiable->routeNotificationFor('slack', $notification);

            if (is_string($route) && $route !== '') {
                return $route;
            }
        }

        $configured = config('ticketing.notifications.slack.webhook');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }
}
