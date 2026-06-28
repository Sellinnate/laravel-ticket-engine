<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

/**
 * Raised when an attachment fails validation (size/mime).
 */
class AttachmentRejectedException extends TicketingException
{
    public static function tooLarge(int $maxKb): self
    {
        return new self("Attachment exceeds the maximum size of {$maxKb} KB.");
    }

    public static function disallowedMime(string $mime): self
    {
        return new self("Attachment mime type [{$mime}] is not allowed.");
    }
}
