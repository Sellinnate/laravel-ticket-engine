<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\TicketActivityFactory;
use Selli\Ticketing\Exceptions\ImmutableAuditException;
use Selli\Ticketing\Models\Builders\ImmutableBuilder;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * Append-only audit trail. Every transition, assignment, field change, merge
 * and sensitive-data access is recorded with actor, before/after and context.
 *
 * Immutability is enforced at the model level: updates and deletes throw.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string $ticket_id
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property string $event
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 */
class TicketActivity extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<TicketActivityFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'ticket_activities';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'context' => 'array',
        ];
    }

    protected static function newFactory(): TicketActivityFactory
    {
        return TicketActivityFactory::new();
    }

    /**
     * Use the immutable builder so mass update/delete also fail (model events
     * alone do not cover bulk operations).
     *
     * @param  Builder  $query
     * @return ImmutableBuilder<static>
     */
    public function newEloquentBuilder($query): ImmutableBuilder
    {
        /** @var ImmutableBuilder<static> $builder */
        $builder = new ImmutableBuilder($query);

        return $builder;
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw ImmutableAuditException::cannotModify();
        });

        static::deleting(function (): never {
            throw ImmutableAuditException::cannotModify();
        });
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
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
