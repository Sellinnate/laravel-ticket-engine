<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\ReferenceGenerator;

/**
 * Per-(tenant, scope) monotonic counter backing human ticket references.
 *
 * Allocation goes through {@see ReferenceGenerator}
 * which locks the row (`lockForUpdate`) so concurrent opens get distinct,
 * gap-resistant sequence values regardless of engine NULL-uniqueness semantics
 * or soft-deleted rows.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property string $scope
 * @property int $next_value
 */
class TicketSequence extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'ticket_sequences';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_value' => 'integer',
        ];
    }
}
