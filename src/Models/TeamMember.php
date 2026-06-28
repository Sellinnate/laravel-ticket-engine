<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\TeamMemberFactory;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * Membership of an agent in a team, carrying skills for skill-based routing and
 * a last-assigned timestamp for round-robin.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string $team_id
 * @property string $member_type
 * @property int|string $member_id
 * @property list<string>|null $skills
 * @property bool $is_active
 * @property Carbon|null $last_assigned_at
 */
class TeamMember extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<TeamMemberFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'team_members';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'is_active' => 'boolean',
            'last_assigned_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TeamMemberFactory
    {
        return TeamMemberFactory::new();
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Ticketing::teamModel(), 'team_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function member(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
