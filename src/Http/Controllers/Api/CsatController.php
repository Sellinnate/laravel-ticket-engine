<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Requests\StoreCsatRequest;
use Selli\Ticketing\Http\Resources\SatisfactionRatingResource;
use Selli\Ticketing\Support\CsatToken;

class CsatController extends Controller
{
    public function store(StoreCsatRequest $request, string $ticket): JsonResponse
    {
        $ticket = $this->resolveTicket($ticket);

        if ($request->filled('token')) {
            $token = (string) $request->string('token');
            $claims = CsatToken::verify($token);

            if ($claims === null) {
                throw ValidationException::withMessages(['token' => 'The CSAT token is invalid or has expired.']);
            }

            // The token must name the SAME ticket as the URL, so a caller can't
            // POST to ticket A's route and have the rating land on ticket B (and
            // bypass the route's tenant scope).
            if ($claims['ticket'] !== (string) $ticket->getKey()) {
                throw ValidationException::withMessages(['token' => 'The CSAT token does not match this ticket.']);
            }

            // The signed token IS the authorization here (it's mailed only to the
            // requester), so no policy check — a logged-in session isn't required.
            $rating = $this->guard('rating', fn () => Ticketing::submitCsatByToken($token, $request->integer('rating'), $request->input('comment'), $request->user()));
        } else {
            $this->authorizeTicket($request->user(), 'submitCsat', $ticket);

            $rating = $this->guard('rating', fn () => Ticketing::submitCsat($ticket, $request->integer('rating'), $request->input('comment'), $request->user()));
        }

        return (new SatisfactionRatingResource($rating))->response()->setStatusCode(201);
    }
}
