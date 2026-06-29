<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

/**
 * Raised when an operation would link a ticket to a team or agent that belongs
 * to a different tenant.
 */
class CrossTenantException extends TicketingException
{
    public static function forAssignment(string $what): self
    {
        return new self("Refusing to assign a ticket to a {$what} from another tenant.");
    }

    public static function forWrite(string $model): self
    {
        return new self("Refusing to write a [{$model}] row to a tenant other than the resolved one.");
    }
}
