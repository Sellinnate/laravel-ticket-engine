<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Concerns\HasCustomFields;
use Selli\Ticketing\Database\Factories\TicketFactory;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * The central aggregate. A ticket may have a polymorphic subject (any host
 * model implementing Ticketable), or none (a free-standing request).
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property string $reference
 * @property int|null $ticket_type_id
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property string|null $category
 * @property Priority $priority
 * @property string $status
 * @property string|null $assignee_type
 * @property int|string|null $assignee_id
 * @property int|null $team_id
 * @property string $title
 * @property array<string, mixed>|null $custom_fields
 * @property Carbon|null $first_response_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $due_at
 * @property int $reopened_count
 */
class Ticket extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;
    use HasCustomFields;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'tickets';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'custom_fields' => 'array',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'due_at' => 'datetime',
            'reopened_count' => 'integer',
        ];
    }

    protected static function newFactory(): TicketFactory
    {
        return TicketFactory::new();
    }

    /**
     * The host entity this ticket is about (nullable).
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The agent currently responsible (nullable polymorphic).
     *
     * @return MorphTo<Model, $this>
     */
    public function assignee(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<TicketType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(Ticketing::ticketTypeModel(), 'ticket_type_id');
    }

    /**
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Ticketing::ticketMessageModel(), 'ticket_id');
    }

    /**
     * @return HasMany<TicketParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Ticketing::ticketParticipantModel(), 'ticket_id');
    }

    /**
     * @return HasMany<TicketActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Ticketing::ticketActivityModel(), 'ticket_id');
    }

    /**
     * @return MorphMany<TicketAttachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Ticketing::ticketAttachmentModel(), 'attachable');
    }

    /**
     * @return HasMany<TicketLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(Ticketing::ticketLinkModel(), 'ticket_id');
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        $prefix = (string) config('ticketing.tables.prefix', '');
        $table = $prefix.(string) config('ticketing.tables.taggables', 'taggables');

        return $this->morphToMany(Ticketing::tagModel(), 'taggable', $table, 'taggable_id', 'tag_id');
    }

    /**
     * Scope to tickets whose status maps to the "open" system semantic.
     *
     * @param  Builder<$this>  $query
     * @param  list<string>  $statuses
     * @return Builder<$this>
     */
    public function scopeWithStatus(Builder $query, array $statuses): Builder
    {
        return $query->whereIn('status', $statuses);
    }
}
