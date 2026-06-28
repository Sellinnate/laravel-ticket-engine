<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Models\Macro;
use Selli\Ticketing\Support\Ticketing;

/**
 * @extends Factory<Macro>
 */
class MacroFactory extends Factory
{
    protected $model = Macro::class;

    /**
     * Honour a host-overridden model (Ticketing::useMacroModel()).
     *
     * @return class-string<Macro>
     */
    public function modelName(): string
    {
        /** @var class-string<Macro> $model */
        $model = Ticketing::macroModel();

        return $model;
    }

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
