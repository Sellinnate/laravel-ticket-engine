<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketType;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'TCK-'.fake()->unique()->numberBetween(1, 1_000_000),
            'ticket_type_id' => TicketType::factory(),
            'title' => fake()->sentence(),
            'category' => null,
            'priority' => Priority::Normal,
            'status' => 'open',
            'custom_fields' => null,
            'reopened_count' => 0,
        ];
    }

    /**
     * Attach a subject (any host model).
     */
    public function about(Model $subject): static
    {
        return $this->state(fn (): array => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function assignedTo(Model $agent): static
    {
        return $this->state(fn (): array => [
            'assignee_type' => $agent->getMorphClass(),
            'assignee_id' => $agent->getKey(),
        ]);
    }
}
