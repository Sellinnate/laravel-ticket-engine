<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * Where a ticket message originated.
 */
enum MessageSource: string
{
    case Api = 'api';
    case Email = 'email';
    case System = 'system';
    case Web = 'web';

    /** Written by the AI assistant. */
    case Ai = 'ai';

    /** Carried over from a widget/chat conversation. */
    case Widget = 'widget';
}
