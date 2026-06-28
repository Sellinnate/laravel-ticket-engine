<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\TeamMember;
use Selli\Ticketing\Support\Ticketing;

/**
 * @extends Factory<TeamMember>
 */
class TeamMemberFactory extends Factory
{
    protected $model = TeamMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Ticketing::teamModel()::factory(),
            'member_type' => 'tickets.agent',
            'member_id' => (string) fake()->unique()->numberBetween(1, 1_000_000),
            'skills' => null,
            'is_active' => true,
            'last_assigned_at' => null,
        ];
    }

    public function member(Model $model): static
    {
        return $this->state(fn (): array => [
            'member_type' => $model->getMorphClass(),
            'member_id' => $model->getKey(),
        ]);
    }

    /**
     * @param  list<string>  $skills
     */
    public function skills(array $skills): static
    {
        return $this->state(fn (): array => ['skills' => $skills]);
    }
}
