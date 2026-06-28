<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Validation\ValidationException;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Requests\StoreAssignmentRequest;
use Selli\Ticketing\Http\Resources\TicketResource;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing as TicketingManager;

class AssignmentController
{
    public function store(StoreAssignmentRequest $request, Ticket $ticket): TicketResource
    {
        $team = null;

        if ($request->filled('team_id')) {
            $team = TicketingManager::teamModel()::query()->find($request->input('team_id'));

            if (! $team instanceof Team) {
                throw ValidationException::withMessages(['team_id' => 'Unknown team.']);
            }
        }

        $assignee = null;

        if ($request->boolean('assign_to_me')) {
            // Only agents can be assigned a ticket — a requester-only account
            // can't self-assign.
            if (! $request->user() instanceof CanActOnTickets) {
                throw ValidationException::withMessages(['assign_to_me' => 'Only agents can be assigned a ticket.']);
            }

            $assignee = $request->user();
        }

        if ($team === null && $assignee === null) {
            throw ValidationException::withMessages(['team_id' => 'Provide team_id or assign_to_me.']);
        }

        $ticket = Ticketing::assign(
            ticket: $ticket,
            assignee: $assignee,
            team: $team,
            strategy: $request->input('strategy'),
            actor: $request->user(),
        );

        return new TicketResource($ticket);
    }
}
