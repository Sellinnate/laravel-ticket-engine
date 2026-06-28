<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Selli\Ticketing\Automation\RuleEngine;
use Selli\Ticketing\Automation\TriggerRegistry;
use Selli\Ticketing\Models\Ticket;

/**
 * Bridges domain events to the automation {@see RuleEngine}: one handler for
 * every trigger event, resolving the event's key, ticket and actor uniformly.
 */
class AutomationSubscriber
{
    public function __construct(protected RuleEngine $engine) {}

    public function onEvent(object $event): void
    {
        $key = TriggerRegistry::keyFor($event);
        $ticket = TriggerRegistry::ticketOf($event);

        if ($key === null || ! $ticket instanceof Ticket) {
            return;
        }

        $this->engine->run($key, $ticket, TriggerRegistry::actorOf($event));
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return array_fill_keys(array_keys(TriggerRegistry::map()), 'onEvent');
    }
}
