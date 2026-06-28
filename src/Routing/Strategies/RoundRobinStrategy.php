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
            ->sort(function (TeamMember $a, TeamMember $b): int {
                $at = $a->last_assigned_at?->getTimestamp();
                $bt = $b->last_assigned_at?->getTimestamp();

                return [$at === null ? 0 : 1, $at ?? 0, $a->getKey()]
                    <=> [$bt === null ? 0 : 1, $bt ?? 0, $b->getKey()];
            })
            ->first();

        return $this->agentFor($member);
    }
}
