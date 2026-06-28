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

        // A membership whose agent no longer exists is not assignable.
        if ($agent === null) {
            return false;
        }

        return ! $agent instanceof ReportsAvailability || $agent->isAvailableForTickets();
    }

    /**
     * Number of unresolved tickets assigned to a member's agent within the
     * ticket's tenant (so least-busy/skill-based aren't skewed by other tenants).
     */
    protected function openTicketCount(TeamMember $member, Ticket $ticket): int
    {
        $model = Ticketing::ticketModel();
        $tenantColumn = $ticket->getTenantColumn();

        return $model::query()
            ->withoutTenancy()
            ->where($tenantColumn, $ticket->getAttribute($tenantColumn))
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
