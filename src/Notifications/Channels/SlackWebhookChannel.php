<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

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
        $url = $this->webhookUrl($notifiable, $notification);

        if ($url === null) {
            return;
        }

        $text = method_exists($notification, 'toSlack')
            ? (string) $notification->toSlack($notifiable)
            : '';

        if ($text === '') {
            return;
        }

        $timeout = max(1, (int) config('ticketing.notifications.slack.timeout', 5));

        // Surface a 4xx/5xx so a failed delivery retries rather than being
        // silently swallowed.
        Http::timeout($timeout)->asJson()->post($url, ['text' => $text])->throw();
    }

    protected function webhookUrl(object $notifiable, Notification $notification): ?string
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
