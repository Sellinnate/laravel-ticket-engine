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
use Selli\Ticketing\Tenancy\TenantGuard;

/**
 * Shared helpers for the built-in assignment strategies.
 *
 * @internal
 */
abstract class AbstractStrategy implements AssignmentStrategy
{
    public function __construct(protected TenantGuard $tenant) {}

    /**
     * Active members of the team whose agent exists, is available, and belongs
     * to the ticket's tenant. The agent relation is eager-loaded to avoid N+1.
     *
     * @return Collection<int, TeamMember>
     */
    protected function eligibleMembers(Ticket $ticket, Team $team): Collection
    {
        $model = Ticketing::teamMemberModel();

        // lockForUpdate so concurrent same-team assignments serialise on the
        // membership rows: the read of rotation/load state (last_assigned_at) and
        // the subsequent stamp are then atomic, instead of both picking the same
        // "least-recently-assigned"/"least-busy" agent off a stale snapshot. Runs
        // inside AssignTicket's transaction (which already holds the ticket lock).
        /** @var Collection<int, TeamMember> $members */
        $members = $model::query()
            ->withoutTenancy()
            ->with('member')
            ->where('team_id', $team->getKey())
            ->where('is_active', true)
            ->lockForUpdate()
            ->get();

        return $members
            ->filter(fn (TeamMember $member): bool => $this->isEligible($member, $ticket))
            ->values();
    }

    protected function isEligible(TeamMember $member, Ticket $ticket): bool
    {
        $agent = $member->member;

        // A membership whose agent no longer exists is not assignable.
        if ($agent === null) {
            return false;
        }

        if ($agent instanceof ReportsAvailability && ! $agent->isAvailableForTickets()) {
            return false;
        }

        // Never route to an agent from another tenant.
        return $this->tenant->belongsToTicketTenant($agent, $ticket);
    }

    /**
     * Each member's number of unresolved tickets within the ticket's tenant (so
     * least-busy/skill-based aren't skewed by other tenants), keyed by the
     * member's key. One grouped query for the whole team rather than one count
     * per member — the comparator then sorts on the precomputed figures.
     *
     * @param  Collection<int, TeamMember>  $members
     * @return array<int|string, int>
     */
    protected function loadCounts(Collection $members, Ticket $ticket): array
    {
        if ($members->isEmpty()) {
            return [];
        }

        $model = Ticketing::ticketModel();
        $tenantColumn = $ticket->getTenantColumn();

        $rows = $model::query()
            ->withoutTenancy()
            ->where($tenantColumn, $ticket->getAttribute($tenantColumn))
            ->whereNull('resolved_at')
            ->whereNull('closed_at')
            ->where(function ($query) use ($members): void {
                foreach ($members->groupBy('member_type') as $type => $group) {
                    $query->orWhere(function ($byType) use ($type, $group): void {
                        $byType->where('assignee_type', $type)
                            ->whereIn('assignee_id', $group->map(fn (TeamMember $member): string => (string) $member->member_id)->all());
                    });
                }
            })
            ->groupBy('assignee_type', 'assignee_id')
            ->selectRaw('assignee_type, assignee_id, COUNT(*) as aggregate')
            ->toBase()
            ->get();

        $byAgent = [];

        foreach ($rows as $row) {
            $byAgent[$row->assignee_type.':'.$row->assignee_id] = (int) $row->aggregate;
        }

        $counts = [];

        foreach ($members as $member) {
            $counts[$member->getKey()] = $byAgent[$member->member_type.':'.$member->member_id] ?? 0;
        }

        return $counts;
    }

    protected function agentFor(?TeamMember $member): ?Model
    {
        return $member?->member;
    }

    abstract public function assign(Ticket $ticket, Team $team): ?Model;
}
