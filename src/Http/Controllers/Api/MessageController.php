<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Requests\StoreMessageRequest;
use Selli\Ticketing\Http\Resources\TicketMessageResource;
use Selli\Ticketing\Models\Ticket;

class MessageController
{
    public function store(StoreMessageRequest $request, Ticket $ticket): JsonResponse
    {
        $message = Ticketing::postMessage(
            ticket: $ticket,
            author: $request->user(),
            body: (string) $request->string('body'),
            visibility: MessageVisibility::tryFrom((string) $request->input('visibility', 'public')) ?? MessageVisibility::Public,
        );

        return (new TicketMessageResource($message))->response()->setStatusCode(201);
    }
}
