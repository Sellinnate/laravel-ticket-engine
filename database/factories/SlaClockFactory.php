<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Enums\SlaTarget;
use Selli\Ticketing\Models\SlaClock;
use Selli\Ticketing\Models\Ticket;

/**
 * @extends Factory<SlaClock>
 */
class SlaClockFactory extends Factory
{
    protected $model = SlaClock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'target' => SlaTarget::Resolution,
            'budget_minutes' => 480,
            'business_hours_id' => null,
            'started_at' => now(),
            'due_at' => now()->addHours(8),
            'paused_at' => null,
            'remaining_minutes' => null,
            'breached_at' => null,
            'completed_at' => null,
            'threshold_notified' => false,
        ];
    }

    public function dueAt(\DateTimeInterface $due): static
    {
        return $this->state(fn (): array => ['due_at' => $due]);
    }

    public function firstResponse(): static
    {
        return $this->state(fn (): array => ['target' => SlaTarget::FirstResponse]);
    }
}
