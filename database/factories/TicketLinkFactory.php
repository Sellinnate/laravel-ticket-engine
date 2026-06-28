<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketLink;

/**
 * @extends Factory<TicketLink>
 */
class TicketLinkFactory extends Factory
{
    protected $model = TicketLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'linkable_type' => 'tickets.link',
            'linkable_id' => (string) fake()->unique()->numberBetween(1, 1000000),
            'role' => 'references',
        ];
    }
}
