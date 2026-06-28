<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;

/**
 * Picks the agent within a team that a ticket should be assigned to. Strategies
 * are interchangeable drivers registered on the AssignmentManager.
 */
interface AssignmentStrategy
{
    /**
     * Return the chosen agent, or null to leave the ticket unassigned (e.g. the
     * manual strategy, or when no eligible agent is available).
     */
    public function assign(Ticket $ticket, Team $team): ?Model;
}
