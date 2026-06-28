<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Validation\ValidationException;
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

        $assignee = $request->boolean('assign_to_me') ? $request->user() : null;

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
