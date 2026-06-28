<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Models\SlaPolicy;

/**
 * @extends Factory<SlaPolicy>
 */
class SlaPolicyFactory extends Factory
{
    protected $model = SlaPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Default SLA',
            'ticket_type_id' => null,
            'priority' => null,
            'first_response_minutes' => 60,
            'next_response_minutes' => null,
            'resolution_minutes' => 480,
            'business_hours_id' => null,
            'pause_in_states' => null,
            'is_active' => true,
        ];
    }
}
