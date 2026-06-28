<?php

declare(strict_types=1);

namespace Selli\Ticketing\Workflow;

use Selli\Ticketing\Contracts\TransitionGuard;

/**
 * An immutable description of a single workflow transition.
 */
final readonly class Transition
{
    /**
     * @param  list<string>  $from  states this transition may start from
     * @param  list<class-string<TransitionGuard>>  $guards
     */
    public function __construct(
        public string $name,
        public array $from,
        public string $to,
        public array $guards = [],
    ) {}

    public function allowsFrom(string $state): bool
    {
        return in_array($state, $this->from, true);
    }
}
