<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Illuminate\Contracts\Container\Container;
use Selli\Ticketing\Automation\RuleEngine;
use Selli\Ticketing\Automation\TriggerRegistry;
use Selli\Ticketing\Models\Ticket;

/**
 * Bridges domain events to the automation {@see RuleEngine}: one handler for
 * every trigger event, resolving the event's key, ticket and actor uniformly.
 */
class AutomationSubscriber
{
    public function __construct(protected Container $container) {}

    public function onEvent(object $event): void
    {
        $key = TriggerRegistry::keyFor($event);
        $ticket = TriggerRegistry::ticketOf($event);

        if ($key === null || ! $ticket instanceof Ticket) {
            return;
        }

        // Resolve the engine from the container (bound scoped), so its re-entrancy
        // depth counter is shared within one request's nested cascade but reset
        // between requests (e.g. a persistent Octane worker).
        $this->container->make(RuleEngine::class)->run($key, $ticket, TriggerRegistry::actorOf($event));
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return array_fill_keys(array_keys(TriggerRegistry::map()), 'onEvent');
    }
}
