<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

/**
 * Decides which channels a given recipient wants a given ticket notification on.
 * Bind your own implementation (per-user / per-tenant preferences UI) via
 * config `ticketing.notifications.preferences`; the package ships a sensible
 * config-driven default.
 */
interface NotificationPreferences
{
    /**
     * The channels the recipient should receive this notification on, chosen
     * from the channels the notification supports.
     *
     * @param  list<string>  $supported  channels the notification can deliver on
     * @return list<string> the subset to actually use (may be empty)
     */
    public function channels(object $notifiable, string $notification, array $supported): array;
}
