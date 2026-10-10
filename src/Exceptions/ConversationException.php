<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

/**
 * A widget/chat conversation refused an operation the caller can correct.
 */
class ConversationException extends TicketingException
{
    public static function closed(): self
    {
        return new self('This conversation is closed.');
    }

    public static function emptyMessage(): self
    {
        return new self('A message cannot be empty.');
    }

    public static function tooManyMessages(): self
    {
        return new self('This conversation has reached its message limit.');
    }

    public static function contactRequired(): self
    {
        return new self('Leave a valid email address so a member of our team can follow up.');
    }
}
