<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Requests\StoreCsatRequest;
use Selli\Ticketing\Http\Resources\SatisfactionRatingResource;
use Selli\Ticketing\Models\Ticket;

class CsatController
{
    public function store(StoreCsatRequest $request, Ticket $ticket): JsonResponse
    {
        $rating = $request->filled('token')
            ? Ticketing::submitCsatByToken((string) $request->string('token'), $request->integer('rating'), $request->input('comment'), $request->user())
            : Ticketing::submitCsat($ticket, $request->integer('rating'), $request->input('comment'), $request->user());

        return (new SatisfactionRatingResource($rating))->response()->setStatusCode(201);
    }
}
