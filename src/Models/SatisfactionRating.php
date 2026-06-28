<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\SatisfactionRatingFactory;
use Selli\Ticketing\Enums\CsatScale;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * One satisfaction rating per ticket. Created (unrated) when CSAT is requested,
 * filled in when the requester submits. Re-requested in place if the ticket is
 * reopened.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string $ticket_id
 * @property CsatScale $scale
 * @property int|null $rating
 * @property string|null $comment
 * @property string|null $submitted_by_type
 * @property int|string|null $submitted_by_id
 * @property Carbon|null $requested_at
 * @property Carbon|null $submitted_at
 */
class SatisfactionRating extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<SatisfactionRatingFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'satisfaction_ratings';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scale' => CsatScale::class,
            'rating' => 'integer',
            'requested_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SatisfactionRatingFactory
    {
        return SatisfactionRatingFactory::new();
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticketing::ticketModel(), 'ticket_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function submittedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /**
     * Whether the rating counts as a satisfied response (top of its scale).
     */
    public function isPositive(): bool
    {
        return $this->rating !== null && $this->scale->isPositive($this->rating);
    }

    /**
     * Scope to ratings the requester has actually submitted.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->whereNotNull('submitted_at');
    }

    /**
     * Scope to ratings submitted within an (inclusive) window — for period CSAT.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSubmittedBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereNotNull('submitted_at')->whereBetween('submitted_at', [$from, $to]);
    }
}
