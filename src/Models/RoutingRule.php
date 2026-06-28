<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\RoutingRuleFactory;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * A data-driven routing rule: an ordered set of conditions that, when matched,
 * routes a ticket to a team (and optionally a strategy or explicit assignee).
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property string $name
 * @property list<array{field: string, operator: string, value: mixed}>|null $conditions
 * @property int|string|null $team_id
 * @property string|null $assignee_type
 * @property int|string|null $assignee_id
 * @property string|null $strategy
 * @property int $position
 * @property bool $is_active
 */
class RoutingRule extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<RoutingRuleFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'routing_rules';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): RoutingRuleFactory
    {
        return RoutingRuleFactory::new();
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Ticketing::teamModel(), 'team_id');
    }
}
