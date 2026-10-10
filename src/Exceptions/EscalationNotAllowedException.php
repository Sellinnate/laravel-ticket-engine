<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

/**
 * Raised when a ticket cannot be handed to humans — it is resolved or closed,
 * so a hand-off would promise help its workflow does not give. Reopen it first.
 */
class EscalationNotAllowedException extends TicketingException
{
    public static function closed(): self
    {
        return new self('A resolved or closed ticket cannot be escalated: reopen it first.');
    }
}
