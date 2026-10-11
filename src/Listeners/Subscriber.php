<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

/**
 * A package event subscriber: maps domain event classes to handler methods.
 * The service provider wires each mapping through a failure-isolating closure.
 *
 * @internal
 */
interface Subscriber
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array;
}
