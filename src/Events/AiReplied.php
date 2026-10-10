<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Models\AiTurn;

/**
 * The AI answered the customer — on a ticket (the message is a TicketMessage)
 * or in a widget conversation (a ConversationMessage).
 */
class AiReplied implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public AiTurn $turn,
        public Model $message,
    ) {}
}
