<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Data\AssignTicketData;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\ParticipantAdded;
use Selli\Ticketing\Events\TicketAssigned;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Routing\AssignmentManager;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantGuard;

/**
 * Assigns a ticket to a team and/or agent. With a team but no explicit assignee
 * it delegates to the configured assignment strategy. Locks the row, validates
 * tenancy, updates the assignee participant, stamps round-robin state, audits
 * and emits events.
 */
class AssignTicket
{
    public function __construct(
        protected AssignmentManager $strategies,
        protected AuditLogger $audit,
        protected TenantGuard $tenant,
    ) {}

    public function handle(AssignTicketData $data): Ticket
    {
        // Nothing requested → no-op (don't touch the current assignment).
        if ($data->team === null && $data->assignee === null) {
            return $data->ticket;
        }

        // Reject cross-tenant assignment from the public API up front.
        if ($data->team !== null && ! $this->tenant->belongsToTicketTenant($data->team, $data->ticket)) {
            throw CrossTenantException::forAssignment('team');
        }

        if ($data->assignee !== null && ! $this->tenant->belongsToTicketTenant($data->assignee, $data->ticket)) {
            throw CrossTenantException::forAssignment('agent');
        }

        $model = Ticketing::ticketModel();

        $result = DB::transaction(function () use ($data, $model): array {
            /** @var Ticket $ticket */
            $ticket = $model::query()->withoutTenancy()->lockForUpdate()->findOrFail($data->ticket->getKey());

            $team = $data->team;
            $assignee = $data->assignee;

            if ($assignee === null && $team !== null) {
                $strategy = $this->strategies->strategy($data->strategy);
                $assignee = $strategy->assign($ticket, $team);
            }

            // A team assignment that resolved no agent leaves the ticket
            // team-owned but unassigned (clearing any prior assignee).
            $clearAssignee = $assignee === null && $team !== null;

            if ($team !== null) {
                $ticket->team_id = $team->getKey();
            }

            if ($assignee !== null) {
                $ticket->assignee_type = $assignee->getMorphClass();
                $ticket->assignee_id = $assignee->getKey();
            } elseif ($clearAssignee) {
                $ticket->assignee_type = null;
                $ticket->assignee_id = null;
            }

            $ticket->save();

            // The ticket's effective team after the save (an explicit assignTo
            // keeps the existing team) drives stamp/audit/event.
            $effectiveTeam = $team ?? $this->currentTeam($ticket);

            $participant = null;

            if ($assignee !== null) {
                $participant = $this->syncAssigneeParticipant($ticket, $assignee);
                $this->stampLastAssigned($effectiveTeam, $assignee);
            } elseif ($clearAssignee) {
                $this->removeAssigneeParticipant($ticket);
            }

            $this->audit->record(
                ticket: $ticket,
                event: 'ticket.assigned',
                actor: $data->actor,
                context: array_filter([
                    'assignee_type' => $assignee?->getMorphClass(),
                    'assignee_id' => $assignee?->getKey(),
                    'team_id' => $effectiveTeam?->getKey(),
                ], fn ($value): bool => $value !== null),
            );

            return [$ticket, $assignee, $effectiveTeam, $participant];
        });

        /** @var array{0: Ticket, 1: ?Model, 2: ?Team, 3: ?TicketParticipant} $result */
        [$ticket, $assignee, $team, $participant] = $result;

        TicketAssigned::dispatch($ticket, $assignee, $team, $data->actor);

        if ($participant !== null && $participant->wasRecentlyCreated) {
            ParticipantAdded::dispatch($ticket, $participant);
        }

        return $ticket;
    }

    protected function syncAssigneeParticipant(Ticket $ticket, Model $assignee): TicketParticipant
    {
        $model = Ticketing::ticketParticipantModel();

        /** @var TicketParticipant $participant */
        $participant = $model::query()->withoutTenancy()->updateOrCreate(
            [
                'ticket_id' => $ticket->getKey(),
                'role' => ParticipantRole::Assignee->value,
            ],
            array_merge($ticket->tenantAttributes(), [
                'participant_type' => $assignee->getMorphClass(),
                'participant_id' => $assignee->getKey(),
                'notify' => true,
            ]),
        );

        return $participant;
    }

    protected function removeAssigneeParticipant(Ticket $ticket): void
    {
        $model = Ticketing::ticketParticipantModel();

        $model::query()
            ->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->where('role', ParticipantRole::Assignee->value)
            ->delete();
    }

    protected function stampLastAssigned(?Team $team, Model $assignee): void
    {
        if ($team === null) {
            return;
        }

        $model = Ticketing::teamMemberModel();

        $model::query()
            ->withoutTenancy()
            ->where('team_id', $team->getKey())
            ->where('member_type', $assignee->getMorphClass())
            ->where('member_id', $assignee->getKey())
            ->update(['last_assigned_at' => now()]);
    }

    protected function currentTeam(Ticket $ticket): ?Team
    {
        if ($ticket->team_id === null) {
            return null;
        }

        $model = Ticketing::teamModel();
        $team = $model::query()->withoutTenancy()->find($ticket->team_id);

        return $team instanceof Team ? $team : null;
    }
}
