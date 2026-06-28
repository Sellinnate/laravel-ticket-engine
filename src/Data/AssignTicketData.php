<?php

declare(strict_types=1);

namespace Selli\Ticketing\Data;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;

/**
 * Typed input for assigning a ticket. Provide an explicit assignee, or a team
 * (optionally with a strategy) to let the engine choose an agent.
 */
final readonly class AssignTicketData
{
    public function __construct(
        public Ticket $ticket,
        public ?Model $assignee = null,
        public ?Team $team = null,
        public ?string $strategy = null,
        public ?Model $actor = null,
    ) {}
}
