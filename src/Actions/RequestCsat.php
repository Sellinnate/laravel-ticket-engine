<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Selli\Ticketing\Events\CsatRequested;
use Selli\Ticketing\Exceptions\CsatException;
use Selli\Ticketing\Models\SatisfactionRating;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Csat;
use Selli\Ticketing\Support\CsatToken;
use Selli\Ticketing\Support\Ticketing;

/**
 * (Re-)arms the satisfaction rating for a ticket and emits {@see CsatRequested}
 * with a signed token. Re-arming clears any prior submission so a reopened-then-
 * resolved ticket can be rated afresh — safe because this only runs on a resolve
 * transition, where a submitted row necessarily belongs to an earlier cycle.
 */
class RequestCsat
{
    public function __construct(protected AuditLogger $audit) {}

    public function handle(Ticket $ticket, ?Model $actor = null): SatisfactionRating
    {
        if (! Csat::enabled()) {
            throw CsatException::disabled();
        }

        $scale = Csat::scale();

        // Resolve the token (TTL + secret) BEFORE persisting, so a misconfigured
        // TTL/secret fails the whole request rather than leaving a "requested"
        // row whose CsatRequested event never fires. A fresh random nonce is the
        // CSAT cycle marker: it is stored on the row AND signed into the token, so
        // a stale link from an earlier cycle can't rate a re-armed request (and,
        // unlike a timestamp, two same-second re-requests can't collide).
        $cycle = Str::random(40);
        $expiresAt = Carbon::now()->addSeconds(Csat::tokenTtl());
        $token = CsatToken::issue($ticket->getKey(), $expiresAt, $cycle);

        $rating = DB::transaction(function () use ($ticket, $scale, $actor, $cycle): SatisfactionRating {
            // Serialise concurrent CSAT operations for this ticket on the ticket
            // row, so two requests can't both miss the rating and race to create
            // it (which the one-per-ticket constraint would reject).
            Ticketing::ticketModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->find($ticket->getKey());

            $model = Ticketing::satisfactionRatingModel();

            // Look up by ticket_id alone — it's the unique key, so a tenant drift
            // on the existing row can't make us miss it and hit the constraint.
            $existing = $model::query()->withoutTenancy()
                ->where('ticket_id', $ticket->getKey())
                ->first();

            $attributes = array_merge($ticket->tenantAttributes(), [
                'ticket_id' => $ticket->getKey(),
                'scale' => $scale->value,
                'cycle' => $cycle,
                'rating' => null,
                'comment' => null,
                'submitted_by_type' => null,
                'submitted_by_id' => null,
                'submitted_at' => null,
                'requested_at' => now(),
            ]);

            if ($existing instanceof SatisfactionRating) {
                $existing->forceFill($attributes)->save();
                $row = $existing;
            } else {
                /** @var SatisfactionRating $row */
                $row = $model::query()->create($attributes);
            }

            $this->audit->record($ticket, 'csat.requested', $actor, context: ['rating_id' => $row->getKey()]);

            return $row;
        });

        CsatRequested::dispatch($ticket, $rating, $token, $expiresAt);

        return $rating;
    }
}
