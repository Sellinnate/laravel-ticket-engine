<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\BusinessHoursFactory;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * A stored working calendar (timezone + weekly schedule). Holidays attach via
 * the related model. {@see \Selli\Ticketing\Sla\BusinessHours} is the value
 * object that performs the actual time math from this data.
 *
 * @property int $id
 * @property int|string|null $tenant_id
 * @property string $name
 * @property string $timezone
 * @property array<int, list<array{0: string, 1: string}>> $schedule
 * @property bool $is_default
 */
class BusinessHours extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<BusinessHoursFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'business_hours';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schedule' => 'array',
            'is_default' => 'boolean',
        ];
    }

    protected static function newFactory(): BusinessHoursFactory
    {
        return BusinessHoursFactory::new();
    }

    /**
     * @return HasMany<Holiday, $this>
     */
    public function holidays(): HasMany
    {
        return $this->hasMany(Ticketing::holidayModel(), 'business_hours_id');
    }
}
