<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Events\CsatSubmitted;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Exceptions\CsatException;
use Selli\Ticketing\Models\SatisfactionRating;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Csat;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantGuard;

/**
 * Records (or updates) the satisfaction rating for a ticket — one per ticket. The
 * rating is validated against the scale it was requested with (or the configured
 * scale if submitted without a prior request), and fails closed on a value the
 * scale doesn't accept or a cross-tenant submitter.
 */
class SubmitCsat
{
    public function __construct(
        protected AuditLogger $audit,
        protected TenantGuard $tenant,
    ) {}

    public function handle(Ticket $ticket, int $rating, ?string $comment = null, ?Model $submittedBy = null): SatisfactionRating
    {
        if (! Csat::enabled()) {
            throw CsatException::disabled();
        }

        if ($submittedBy !== null && ! $this->tenant->belongsToTicketTenant($submittedBy, $ticket)) {
            throw CrossTenantException::forAssignment('csat');
        }

        $result = DB::transaction(function () use ($ticket, $rating, $comment, $submittedBy): SatisfactionRating {
            // Serialise concurrent CSAT operations for this ticket on the ticket
            // row, so two submits can't both miss the rating and race to create
            // it (the one-per-ticket constraint would reject the loser).
            Ticketing::ticketModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->find($ticket->getKey());

            $model = Ticketing::satisfactionRatingModel();

            // Look up by ticket_id alone — it's the unique key, so a tenant drift
            // on the existing row can't make us miss it and hit the constraint.
            $existing = $model::query()->withoutTenancy()
                ->where('ticket_id', $ticket->getKey())
                ->first();

            // Honour the scale the rating was requested with so a config change
            // can't retro-invalidate an in-flight request.
            $scale = $existing instanceof SatisfactionRating ? $existing->scale : Csat::scale();

            if (! $scale->accepts($rating)) {
                throw CsatException::invalidRating($rating, $scale);
            }

            $attributes = array_merge($ticket->tenantAttributes(), [
                'ticket_id' => $ticket->getKey(),
                'scale' => $scale->value,
                'rating' => $rating,
                'comment' => $comment,
                'submitted_at' => now(),
                'submitted_by_type' => $submittedBy?->getMorphClass(),
                'submitted_by_id' => $submittedBy?->getKey(),
            ]);

            if ($existing instanceof SatisfactionRating) {
                $existing->forceFill($attributes)->save();
                $row = $existing;
            } else {
                /** @var SatisfactionRating $row */
                $row = $model::query()->create($attributes);
            }

            $this->audit->record($ticket, 'csat.submitted', $submittedBy, context: [
                'rating' => $rating,
                'rating_id' => $row->getKey(),
            ]);

            return $row;
        });

        CsatSubmitted::dispatch($ticket, $result);

        return $result;
    }
}
