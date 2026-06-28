<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Models\TicketLink;
use Selli\Ticketing\Support\Ticketing;

/**
 * @extends Factory<TicketLink>
 */
class TicketLinkFactory extends Factory
{
    protected $model = TicketLink::class;

    /**
     * Honour a host-overridden model (Ticketing::useTicketLinkModel()).
     *
     * @return class-string<TicketLink>
     */
    public function modelName(): string
    {
        /** @var class-string<TicketLink> $model */
        $model = Ticketing::ticketLinkModel();

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ticketModel = Ticketing::ticketModel();

        return [
            'ticket_id' => $ticketModel::factory(),
            // Default to a real (another) ticket as the linked subject.
            'linkable_type' => (new $ticketModel)->getMorphClass(),
            'linkable_id' => $ticketModel::factory(),
            'role' => 'references',
        ];
    }
}
