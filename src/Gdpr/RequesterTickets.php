<?php

declare(strict_types=1);

namespace Selli\Ticketing\Gdpr;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Support\Ticketing;

/**
 * The tickets a given requester model is connected to — as the subject (the
 * for() target) or an explicit participant. Unscoped by tenant: a GDPR request
 * concerns the person across every tenant they appear in.
 */
class RequesterTickets
{
    /**
     * @return Builder<Ticket>
     */
    public static function query(Model $requester): Builder
    {
        /** @var Builder<Ticket> $query */
        $query = Ticketing::ticketModel()::withoutTenancy();

        // Include soft-deleted tickets — a trashed row (e.g. a source ticket a
        // merge soft-deleted) still holds the requester's denormalised PII, which
        // erasure/retention must reach.
        if (self::softDeletes(Ticketing::ticketModel())) {
            $query->withTrashed();
        }

        return $query->where(function (Builder $scoped) use ($requester): void {
            $scoped
                ->where(function (Builder $subject) use ($requester): void {
                    $subject->where('subject_type', $requester->getMorphClass())
                        ->where('subject_id', $requester->getKey());
                })
                ->orWhereHas('participants', function (Builder $participant) use ($requester): void {
                    // Only the REQUESTER participation counts as "their ticket".
                    // Matching any role would pull in tickets where this person is
                    // merely an assignee/watcher — i.e. another customer's
                    // correspondence — into their GDPR export. withoutTenancy so a
                    // cross-tenant requester ticket isn't missed.
                    /** @var Builder<TicketParticipant> $participant */
                    $participant->withoutTenancy()
                        ->where('role', ParticipantRole::Requester->value)
                        ->where('participant_type', $requester->getMorphClass())
                        ->where('participant_id', $requester->getKey());
                });
        });
    }

    /**
     * Whether a model class uses SoftDeletes (so GDPR queries should reach
     * trashed rows that may still hold personal data).
     *
     * @param  class-string<Model>  $model
     */
    public static function softDeletes(string $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
