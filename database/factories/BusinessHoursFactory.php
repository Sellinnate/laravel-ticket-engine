<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Models\BusinessHours;

/**
 * @extends Factory<BusinessHours>
 */
class BusinessHoursFactory extends Factory
{
    protected $model = BusinessHours::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $week = [];
        for ($day = 1; $day <= 5; $day++) {
            $week[$day] = [['09:00', '18:00']];
        }

        return [
            'name' => 'Office Hours',
            'timezone' => 'UTC',
            'schedule' => $week,
            'is_default' => false,
        ];
    }

    public function alwaysOpen(): static
    {
        $week = [];
        for ($day = 1; $day <= 7; $day++) {
            $week[$day] = [['00:00', '24:00']];
        }

        return $this->state(fn (): array => ['name' => '24/7', 'schedule' => $week]);
    }
}
