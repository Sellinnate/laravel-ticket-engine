<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Models\Conversation;

/**
 * A conversation ended without becoming a ticket (resolved_by_ai tells whether
 * the AI answered it).
 */
class ConversationClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Conversation $conversation) {}
}
