<?php

declare(strict_types=1);

namespace Selli\Ticketing\Routing\Strategies;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\AssignmentStrategy;
use Selli\Ticketing\Contracts\ReportsAvailability;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\TeamMember;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;

/**
 * Shared helpers for the built-in assignment strategies.
 */
abstract class AbstractStrategy implements AssignmentStrategy
{
    /**
     * Active members of the team whose agent is available (agents not
     * implementing ReportsAvailability are always available).
     *
     * @return Collection<int, TeamMember>
     */
    protected function eligibleMembers(Team $team): Collection
    {
        $model = Ticketing::teamMemberModel();

        /** @var Collection<int, TeamMember> $members */
        $members = $model::query()
            ->withoutTenancy()
            ->where('team_id', $team->getKey())
            ->where('is_active', true)
            ->get();

        return $members->filter(fn (TeamMember $member): bool => $this->isAvailable($member))->values();
    }

    protected function isAvailable(TeamMember $member): bool
    {
        $agent = $member->member;

        return ! $agent instanceof ReportsAvailability || $agent->isAvailableForTickets();
    }

    /**
     * Number of unresolved tickets currently assigned to a member's agent.
     */
    protected function openTicketCount(TeamMember $member): int
    {
        $model = Ticketing::ticketModel();

        return $model::query()
            ->withoutTenancy()
            ->where('assignee_type', $member->member_type)
            ->where('assignee_id', $member->member_id)
            ->whereNull('resolved_at')
            ->whereNull('closed_at')
            ->count();
    }

    protected function agentFor(?TeamMember $member): ?Model
    {
        return $member?->member;
    }

    abstract public function assign(Ticket $ticket, Team $team): ?Model;
}
