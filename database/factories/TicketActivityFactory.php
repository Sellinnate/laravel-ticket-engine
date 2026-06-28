<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketActivity;

/**
 * @extends Factory<TicketActivity>
 */
class TicketActivityFactory extends Factory
{
    protected $model = TicketActivity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'event' => 'ticket.opened',
            'changes' => null,
            'context' => null,
        ];
    }
}
