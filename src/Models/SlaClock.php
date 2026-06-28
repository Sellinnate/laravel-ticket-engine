<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\SlaClockFactory;
use Selli\Ticketing\Enums\SlaTarget;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * The runtime state of a single SLA timer for a ticket/target. due_at is the
 * deadline in working time; while paused, remaining_minutes holds the budget so
 * the deadline can be recomputed on resume.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string $ticket_id
 * @property SlaTarget $target
 * @property int|null $budget_minutes
 * @property int|string|null $business_hours_id
 * @property Carbon $started_at
 * @property Carbon $due_at
 * @property Carbon|null $paused_at
 * @property int|null $remaining_minutes
 * @property Carbon|null $breached_at
 * @property Carbon|null $completed_at
 * @property bool $threshold_notified
 */
class SlaClock extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<SlaClockFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'sla_clocks';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target' => SlaTarget::class,
            'budget_minutes' => 'integer',
            'started_at' => 'datetime',
            'due_at' => 'datetime',
            'paused_at' => 'datetime',
            'remaining_minutes' => 'integer',
            'breached_at' => 'datetime',
            'completed_at' => 'datetime',
            'threshold_notified' => 'boolean',
        ];
    }

    protected static function newFactory(): SlaClockFactory
    {
        return SlaClockFactory::new();
    }

    public function isRunning(): bool
    {
        return $this->completed_at === null && $this->paused_at === null;
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null && $this->completed_at === null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticketing::ticketModel(), 'ticket_id');
    }

    /**
     * Clocks that are still running (not paused, not completed).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('completed_at')->whereNull('paused_at');
    }
}
