<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\TicketTypeFactory;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * The central axis of configuration: a type determines which workflow applies,
 * which SLA policies, which routing rules and which custom fields.
 *
 * @property int $id
 * @property int|string|null $tenant_id
 * @property string $key
 * @property string $name
 * @property string $workflow
 * @property Priority $default_priority
 * @property array<int, array<string, mixed>>|null $custom_fields_schema
 * @property bool $is_active
 */
class TicketType extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<TicketTypeFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'ticket_types';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_priority' => Priority::class,
            'custom_fields_schema' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): TicketTypeFactory
    {
        return TicketTypeFactory::new();
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticketing::ticketModel(), 'ticket_type_id');
    }
}
