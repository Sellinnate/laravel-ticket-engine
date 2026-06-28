<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\TicketParticipantFactory;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * A participant on a ticket with a typed role. Multiple participants with
 * distinct roles can coexist without binding to a single user model.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string $ticket_id
 * @property string $participant_type
 * @property int|string $participant_id
 * @property ParticipantRole $role
 * @property bool $notify
 */
class TicketParticipant extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<TicketParticipantFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'ticket_participants';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ParticipantRole::class,
            'notify' => 'boolean',
        ];
    }

    protected static function newFactory(): TicketParticipantFactory
    {
        return TicketParticipantFactory::new();
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
    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWithRole(Builder $query, ParticipantRole $role): Builder
    {
        return $query->where('role', $role->value);
    }
}
