<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

/**
 * Raised when a transition cannot be applied — either it is not valid from the
 * current state, or a guard denied it.
 */
class TransitionNotAllowedException extends TicketingException
{
    public static function invalidFromState(string $transition, string $from): self
    {
        return new self("Transition [{$transition}] cannot be applied from state [{$from}].");
    }

    public static function guardDenied(string $transition, string $reason): self
    {
        return new self("Transition [{$transition}] denied: {$reason}");
    }
}
