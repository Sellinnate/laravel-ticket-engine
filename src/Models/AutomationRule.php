<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\AutomationRuleFactory;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;

/**
 * A data-driven automation rule: when a domain event fires, if the ticket
 * matches the conditions, run the actions. Per-tenant, ordered by priority.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property string $name
 * @property string $event
 * @property string $match
 * @property array<int, array<string, mixed>>|null $conditions
 * @property array<int, array<string, mixed>>|null $actions
 * @property bool $is_active
 * @property int $priority
 * @property bool $stop_processing
 */
class AutomationRule extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<AutomationRuleFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'automation_rules';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'stop_processing' => 'boolean',
        ];
    }

    protected static function newFactory(): AutomationRuleFactory
    {
        return AutomationRuleFactory::new();
    }

    /**
     * Scope to the active rules for a trigger event, in execution order.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForTrigger(Builder $query, string $event): Builder
    {
        return $query->where('event', $event)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy($this->getKeyName());
    }
}
