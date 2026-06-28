<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\TicketLinkFactory;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * A related subject linked to a ticket (beyond its primary subject) with a
 * typed role: affects | references | caused_by.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string $ticket_id
 * @property string $linkable_type
 * @property int|string $linkable_id
 * @property string $role
 */
class TicketLink extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<TicketLinkFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'ticket_links';
    }

    protected static function newFactory(): TicketLinkFactory
    {
        return TicketLinkFactory::new();
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
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
