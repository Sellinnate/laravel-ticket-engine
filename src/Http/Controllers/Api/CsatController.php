<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Selli\Ticketing\Exceptions\CsatException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Requests\StoreCsatRequest;
use Selli\Ticketing\Http\Resources\SatisfactionRatingResource;
use Selli\Ticketing\Models\SatisfactionRating;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\CsatToken;

class CsatController
{
    public function store(StoreCsatRequest $request, Ticket $ticket): JsonResponse
    {
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

            $rating = $this->guarded(fn () => Ticketing::submitCsatByToken($token, $request->integer('rating'), $request->input('comment'), $request->user()));
        } else {
            $rating = $this->guarded(fn () => Ticketing::submitCsat($ticket, $request->integer('rating'), $request->input('comment'), $request->user()));
        }

        return (new SatisfactionRatingResource($rating))->response()->setStatusCode(201);
    }

    /**
     * Translate a domain CSAT rejection (stale-cycle token, CSAT disabled, an
     * out-of-scale value) into a 422 instead of a 500.
     *
     * @param  \Closure(): SatisfactionRating  $callback
     */
    protected function guarded(\Closure $callback): SatisfactionRating
    {
        try {
            return $callback();
        } catch (CsatException $exception) {
            throw ValidationException::withMessages(['rating' => $exception->getMessage()]);
        }
    }
}
