<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;

/**
 * A non-working date. A null business_hours_id applies the holiday to every
 * calendar in the tenant (e.g. national holidays).
 *
 * @property int $id
 * @property int|string|null $tenant_id
 * @property int|string|null $business_hours_id
 * @property Carbon $date
 * @property string|null $name
 */
class Holiday extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'holidays';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
