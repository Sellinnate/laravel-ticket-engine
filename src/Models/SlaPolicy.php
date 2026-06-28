<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\SlaPolicyFactory;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * Ties response/resolution time targets to a TicketType + priority combination
 * (with catch-all fallbacks), optionally against a working calendar and with a
 * set of states that pause the clock.
 *
 * @property int $id
 * @property int|string|null $tenant_id
 * @property string $name
 * @property int|null $ticket_type_id
 * @property int|null $priority
 * @property int|null $first_response_minutes
 * @property int|null $next_response_minutes
 * @property int|null $resolution_minutes
 * @property int|null $business_hours_id
 * @property list<string>|null $pause_in_states
 * @property bool $is_active
 */
class SlaPolicy extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<SlaPolicyFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'sla_policies';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'first_response_minutes' => 'integer',
            'next_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
            'pause_in_states' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): SlaPolicyFactory
    {
        return SlaPolicyFactory::new();
    }

    /**
     * @return BelongsTo<TicketType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(Ticketing::ticketTypeModel(), 'ticket_type_id');
    }

    /**
     * @return BelongsTo<BusinessHours, $this>
     */
    public function businessHours(): BelongsTo
    {
        return $this->belongsTo(Ticketing::businessHoursModel(), 'business_hours_id');
    }
}
