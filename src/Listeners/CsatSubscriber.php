<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Selli\Ticketing\Actions\RequestCsat;
use Selli\Ticketing\Events\CsatRequested;
use Selli\Ticketing\Events\TicketResolved;
use Selli\Ticketing\Support\Csat;

/**
 * Requests a satisfaction rating when a ticket is resolved (if CSAT is enabled
 * and configured to auto-request). The host app turns the resulting
 * {@see CsatRequested} into a mail/notification.
 */
class CsatSubscriber
{
    public function __construct(protected RequestCsat $request) {}

    public function onResolved(TicketResolved $event): void
    {
        if (! Csat::autoRequest()) {
            return;
        }

        $this->request->handle($event->ticket, $event->actor);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [TicketResolved::class => 'onResolved'];
    }
}
