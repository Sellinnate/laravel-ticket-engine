<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Http\Request;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Resources\TicketResource;

/**
 * Human takeover and escalation over the API.
 *
 * @internal
 */
class AiController extends Controller
{
    /** POST /tickets/{ticket}/takeover — an agent takes the ticket from the AI. */
    public function takeOver(Request $request, string $ticket): TicketResource
    {
        $model = $this->resolveTicket($ticket);
        $this->authorizeTicket($request->user(), 'takeOver', $model);

        $agent = $this->actor($request);
        abort_unless($agent instanceof CanActOnTickets, 403);

        $assign = $request->boolean('assign', true);

        return new TicketResource(Ticketing::takeOver($model, $agent, $assign));
    }

    /**
     * POST /tickets/{ticket}/escalate — hand the ticket to humans. A requester
     * asking for a person escalates with "human_requested"; an agent escalates
     * manually.
     */
    public function escalate(Request $request, string $ticket): TicketResource
    {
        $model = $this->resolveTicket($ticket);
        $this->authorizeTicket($request->user(), 'escalate', $model);

        $actor = $this->actor($request);
        $reason = $actor instanceof CanActOnTickets ? EscalationReason::Manual : EscalationReason::HumanRequested;

        return new TicketResource(Ticketing::escalate($model, $reason, $actor, notifyCustomer: $reason === EscalationReason::HumanRequested));
    }
}
