<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Models\Ticket;

/**
 * An agent took over a ticket the AI was handling (explicitly, or by replying
 * publicly). The AI never writes on it again.
 */
class HumanTookOver implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public Model $agent,
        public ?AiStatus $previous = null,
    ) {}
}
