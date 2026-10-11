<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\Ticket;

/**
 * A widget/chat conversation became a ticket (with its transcript).
 */
class ConversationEscalated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public Ticket $ticket,
    ) {}
}
