<?php

declare(strict_types=1);

namespace Selli\Ticketing\Routing\Strategies;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\TeamMember;
use Selli\Ticketing\Models\Ticket;

/**
 * Filters team members by the skills a ticket requires (its `required_skills`
 * custom field), then applies least-busy among the qualified members.
 */
class SkillBasedStrategy extends AbstractStrategy
{
    public function assign(Ticket $ticket, Team $team): ?Model
    {
        $required = $this->requiredSkills($ticket);

        $member = $this->eligibleMembers($team)
            ->filter(fn (TeamMember $member): bool => $this->hasSkills($member, $required))
            ->sort(fn (TeamMember $a, TeamMember $b): int => [$this->openTicketCount($a, $ticket), $a->getKey()]
                <=> [$this->openTicketCount($b, $ticket), $b->getKey()])
            ->first();

        return $this->agentFor($member);
    }

    /**
     * @return list<string>
     */
    protected function requiredSkills(Ticket $ticket): array
    {
        $skills = $ticket->customField('required_skills', []);

        return is_array($skills) ? array_values(array_map('strval', $skills)) : [];
    }

    /**
     * @param  list<string>  $required
     */
    protected function hasSkills(TeamMember $member, array $required): bool
    {
        if ($required === []) {
            return true;
        }

        $skills = is_array($member->skills) ? $member->skills : [];

        return array_diff($required, $skills) === [];
    }
}
