<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\MacroFactory;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;

/**
 * A set of operations applied to a ticket in one shot (transition + assignment +
 * reply + tags), to standardise repetitive flows.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property string $key
 * @property string $name
 * @property array<string, mixed> $actions
 * @property int|null $ticket_type_id
 * @property bool $is_active
 */
class Macro extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<MacroFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'macros';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['actions' => 'array', 'is_active' => 'boolean'];
    }

    protected static function newFactory(): MacroFactory
    {
        return MacroFactory::new();
    }
}
