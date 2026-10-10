<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * What an AI turn ended up doing.
 */
enum AiOutcome: string
{
    case Answered = 'answered';
    case Drafted = 'drafted';
    case Escalated = 'escalated';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
