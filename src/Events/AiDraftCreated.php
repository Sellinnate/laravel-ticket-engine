<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Models\AiTurn;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;

/**
 * In "draft" mode the AI wrote an internal draft reply for an agent to review.
 */
class AiDraftCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public TicketMessage $draft,
        public AiTurn $turn,
    ) {}
}
