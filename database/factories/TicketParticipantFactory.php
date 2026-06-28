<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketParticipant;

/**
 * @extends Factory<TicketParticipant>
 */
class TicketParticipantFactory extends Factory
{
    protected $model = TicketParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'participant_type' => 'tickets.actor',
            'participant_id' => (string) fake()->unique()->numberBetween(1, 1_000_000),
            'role' => ParticipantRole::Requester,
            'notify' => true,
        ];
    }

    public function participant(Model $model): static
    {
        return $this->state(fn (): array => [
            'participant_type' => $model->getMorphClass(),
            'participant_id' => $model->getKey(),
        ]);
    }

    public function role(ParticipantRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }
}
