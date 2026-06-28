<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\TicketMessageFactory;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * A single entry in a ticket's conversation. Visibility separates customer-facing
 * correspondence from internal agent notes; the distinction is a domain
 * guarantee applied at query and policy level.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string $ticket_id
 * @property string|null $author_type
 * @property int|string|null $author_id
 * @property MessageVisibility $visibility
 * @property string $body
 * @property BodyFormat $body_format
 * @property MessageSource $source
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TicketMessage extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<TicketMessageFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'ticket_messages';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => MessageVisibility::class,
            'body_format' => BodyFormat::class,
            'source' => MessageSource::class,
            'meta' => 'array',
        ];
    }

    protected static function newFactory(): TicketMessageFactory
    {
        return TicketMessageFactory::new();
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
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Restrict to public (customer-facing) messages only.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('visibility', MessageVisibility::Public->value);
    }

    /**
     * Restrict to internal notes only.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('visibility', MessageVisibility::Internal->value);
    }
}
