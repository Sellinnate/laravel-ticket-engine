<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

/**
 * Raised when a transition name does not exist in a workflow.
 */
class UnknownTransitionException extends TicketingException
{
    public static function make(string $workflow, string $transition): self
    {
        return new self("Workflow [{$workflow}] has no transition named [{$transition}].");
    }
}
