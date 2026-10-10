<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * The lifecycle of a chat/widget conversation.
 */
enum ConversationStatus: string
{
    /** Being answered by the AI (or waiting for the visitor). */
    case Open = 'open';

    /** Turned into a ticket: humans continue it there. */
    case Escalated = 'escalated';

    /** Ended by the visitor or by inactivity. */
    case Closed = 'closed';
}
