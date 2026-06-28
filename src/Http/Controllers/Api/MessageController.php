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
        $this->authorizeTicket($request->user(), 'comment', $ticket);

        $message = Ticketing::postMessage(
            ticket: $ticket,
            author: $request->user(),
            body: (string) $request->string('body'),
            visibility: MessageVisibility::tryFrom((string) $request->input('visibility', 'public')) ?? MessageVisibility::Public,
        );

        return (new TicketMessageResource($message))->response()->setStatusCode(201);
    }
}
