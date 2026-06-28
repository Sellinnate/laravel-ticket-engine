<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Requests\StoreTransitionRequest;
use Selli\Ticketing\Http\Resources\TicketResource;
use Selli\Ticketing\Models\Ticket;

class TransitionController extends Controller
{
    public function store(StoreTransitionRequest $request, Ticket $ticket): TicketResource
    {
        $ticket = $this->guard('transition', fn () => Ticketing::transition(
            ticket: $ticket,
            transition: (string) $request->string('transition'),
            actor: $request->user(),
            note: $request->input('note'),
        ));

        return new TicketResource($ticket);
    }
}
