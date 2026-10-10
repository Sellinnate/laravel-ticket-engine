<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Models\Ticket;

/**
 * A ticket was handed to humans — by an escalation rule, a provider error, the
 * customer's request or explicitly. Fired after routing, so the ticket carries
 * its assignee/team.
 */
class TicketEscalated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public EscalationReason $reason,
        public ?Model $actor = null,
    ) {}
}
