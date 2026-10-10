<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Selli\Ticketing\Enums\InboundOutcome;
use Selli\Ticketing\Models\TicketMessage;

/**
 * The outcome of one inbound email, and the message it became (if any).
 */
final readonly class InboundResult
{
    public function __construct(
        public InboundOutcome $outcome,
        public ?TicketMessage $message = null,
    ) {}
}
