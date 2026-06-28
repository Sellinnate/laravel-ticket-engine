<?php

declare(strict_types=1);

namespace Selli\Ticketing\Routing\Strategies;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\TeamMember;
use Selli\Ticketing\Models\Ticket;

/**
 * Cyclic, fair distribution: the least-recently-assigned member is chosen. The
 * AssignTicket action stamps `last_assigned_at` so the rotation advances.
 */
class RoundRobinStrategy extends AbstractStrategy
{
    public function assign(Ticket $ticket, Team $team): ?Model
    {
        $member = $this->eligibleMembers($team)
            ->sortBy([
                fn (TeamMember $member): int => $member->last_assigned_at === null ? 0 : 1,
                fn (TeamMember $member): string => (string) $member->last_assigned_at?->getTimestamp(),
                fn (TeamMember $member): string => (string) $member->getKey(),
            ])
            ->first();

        return $this->agentFor($member);
    }
}
