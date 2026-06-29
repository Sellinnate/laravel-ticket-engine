<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Requests\StoreMessageRequest;
use Selli\Ticketing\Http\Resources\TicketMessageResource;

class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, string $ticket): JsonResponse
    {
        $ticket = $this->resolveTicket($ticket);

        $visibility = MessageVisibility::tryFrom((string) $request->input('visibility', 'public')) ?? MessageVisibility::Public;

        // Authorize the ability that matches the visibility, so a host policy that
        // distinguishes commentInternal from comment is enforced here — not left
        // to rely solely on the Form Request's agent check.
        $this->authorizeTicket($request->user(), $visibility->isInternal() ? 'commentInternal' : 'comment', $ticket);

        $message = Ticketing::postMessage(
            ticket: $ticket,
            author: $request->user(),
            body: (string) $request->string('body'),
            visibility: $visibility,
        );

        return (new TicketMessageResource($message))->response()->setStatusCode(201);
    }
}
