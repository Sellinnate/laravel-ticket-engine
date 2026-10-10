<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * Who wrote a conversation message.
 */
enum ConversationRole: string
{
    case Visitor = 'visitor';
    case Assistant = 'assistant';
    case Agent = 'agent';
    case System = 'system';
}
