<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\EscalationRecipients;
use Selli\Ticketing\Models\TeamMember;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantGuard;

/**
 * Default escalation recipients: the ticket's assignee, plus — when
 * `ai.escalation.notify_leads` is on — the active leads of its team, each once
 * and only within the ticket's tenant.
 */
class TeamLeadEscalationRecipients implements EscalationRecipients
{
    public function __construct(protected TenantGuard $tenants) {}

    public function for(Ticket $ticket): array
    {
        $recipients = [];

        $assignee = $ticket->assignee;

        if ($assignee instanceof Model) {
            $recipients[$assignee->getMorphClass().':'.$assignee->getKey()] = $assignee;
        }

        if ($ticket->team_id !== null && config('ticketing.ai.escalation.notify_leads', true) !== false) {
            $leads = Ticketing::teamMemberModel()::query()->withoutTenancy()
                ->with('member')
                ->where('team_id', $ticket->team_id)
                ->where('role', TeamMember::ROLE_LEAD)
                ->where('is_active', true)
                ->get();

            foreach ($leads as $lead) {
                $model = $lead->member;

                if ($model instanceof Model && $this->tenants->belongsToTicketTenant($model, $ticket)) {
                    $recipients[$model->getMorphClass().':'.$model->getKey()] = $model;
                }
            }
        }

        return array_values($recipients);
    }
}
