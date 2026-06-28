<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

/**
 * Raised when opening a ticket for a type key that does not exist for the
 * current tenant.
 */
class UnknownTicketTypeException extends TicketingException
{
    public static function forKey(string $key): self
    {
        return new self("No ticket type registered for key [{$key}] in the current tenant.");
    }
}
