<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Models\TicketType;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    protected $model = TicketType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'workflow' => 'default',
            'default_priority' => Priority::Normal,
            'custom_fields_schema' => null,
            'is_active' => true,
        ];
    }
}
