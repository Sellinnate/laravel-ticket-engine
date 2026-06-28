<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Models\AutomationRule;
use Selli\Ticketing\Support\Ticketing;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    protected $model = AutomationRule::class;

    /**
     * Honour a host-overridden model (Ticketing::useAutomationRuleModel()).
     *
     * @return class-string<AutomationRule>
     */
    public function modelName(): string
    {
        /** @var class-string<AutomationRule> $model */
        $model = Ticketing::automationRuleModel();

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'event' => 'ticket.opened',
            'match' => 'all',
            'conditions' => [],
            'actions' => [],
            'is_active' => true,
            'priority' => 0,
            'stop_processing' => false,
        ];
    }
}
