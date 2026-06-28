<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Models\Macro;

/**
 * @extends Factory<Macro>
 */
class MacroFactory extends Factory
{
    protected $model = Macro::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'actions' => [],
            'ticket_type_id' => null,
            'is_active' => true,
        ];
    }
}
