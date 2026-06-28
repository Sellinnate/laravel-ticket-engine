<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Selli\Ticketing\Events\TicketOpened;
use Selli\Ticketing\Routing\RoutingEngine;

/**
 * Runs the routing rules when a ticket is opened.
 */
class RoutingSubscriber
{
    public function __construct(protected RoutingEngine $engine) {}

    public function onOpened(TicketOpened $event): void
    {
        $this->engine->route($event->ticket, $event->requester);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            TicketOpened::class => 'onOpened',
        ];
    }
}
