<?php

declare(strict_types=1);

namespace Selli\Ticketing\Routing\Strategies;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\TeamMember;
use Selli\Ticketing\Models\Ticket;

/**
 * Assigns to the member with the fewest unresolved tickets (real load
 * balancing), breaking ties by least-recently-assigned then key.
 */
class LeastBusyStrategy extends AbstractStrategy
{
    public function assign(Ticket $ticket, Team $team): ?Model
    {
        $member = $this->eligibleMembers($team)
            ->sort(fn (TeamMember $a, TeamMember $b): int => [$this->openTicketCount($a, $ticket), $a->last_assigned_at?->getTimestamp() ?? 0, $a->getKey()]
                <=> [$this->openTicketCount($b, $ticket), $b->last_assigned_at?->getTimestamp() ?? 0, $b->getKey()])
            ->first();

        return $this->agentFor($member);
    }
}
