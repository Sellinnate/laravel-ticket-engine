<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * The three independent SLA timers a ticket can be measured against.
 */
enum SlaTarget: string
{
    /** Time to the first public agent response. */
    case FirstResponse = 'first_response';

    /** Time between subsequent responses while awaiting the agent. */
    case NextResponse = 'next_response';

    /** Time to resolution. */
    case Resolution = 'resolution';
}
