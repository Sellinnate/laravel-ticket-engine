<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

/**
 * Raised when code attempts to mutate or delete an append-only audit record.
 */
class ImmutableAuditException extends TicketingException
{
    public static function cannotModify(): self
    {
        return new self('Ticket activity records are append-only and cannot be updated or deleted.');
    }
}
