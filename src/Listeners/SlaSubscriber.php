<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Events\StateTransitioned;
use Selli\Ticketing\Events\TicketOpened;
use Selli\Ticketing\Events\TicketReopened;
use Selli\Ticketing\Events\TicketResolved;
use Selli\Ticketing\Sla\SlaManager;

/**
 * Wires the SLA engine to the domain events. Registered as an event subscriber
 * so SLA management is fully decoupled from the actions that emit the events.
 */
class SlaSubscriber
{
    public function __construct(protected SlaManager $sla) {}

    public function onOpened(TicketOpened $event): void
    {
        $this->sla->startClocks($event->ticket);
    }

    public function onMessage(MessagePosted $event): void
    {
        $this->sla->handleMessage($event->ticket, $event->message);
    }

    public function onTransition(StateTransitioned $event): void
    {
        $this->sla->syncForTransition($event->ticket, $event->from, $event->to);
    }

    public function onResolved(TicketResolved $event): void
    {
        $this->sla->handleResolved($event->ticket);
    }

    public function onReopened(TicketReopened $event): void
    {
        $this->sla->handleReopened($event->ticket);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            TicketOpened::class => 'onOpened',
            MessagePosted::class => 'onMessage',
            StateTransitioned::class => 'onTransition',
            TicketResolved::class => 'onResolved',
            TicketReopened::class => 'onReopened',
        ];
    }
}
