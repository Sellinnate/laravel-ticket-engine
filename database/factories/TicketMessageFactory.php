<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\Ticketing;

/**
 * @extends Factory<TicketMessage>
 */
class TicketMessageFactory extends Factory
{
    protected $model = TicketMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Honour a host's useTicketModel()/config override rather than
            // hard-coding the package Ticket, so a factory-built message attaches
            // to the configured parent model.
            'ticket_id' => Ticketing::ticketModel()::factory(),
            'visibility' => MessageVisibility::Public,
            'body' => fake()->paragraph(),
            'body_format' => BodyFormat::Text,
            'source' => MessageSource::Api,
            'meta' => null,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (): array => ['visibility' => MessageVisibility::Internal]);
    }

    public function from(Model $author): static
    {
        return $this->state(fn (): array => [
            'author_type' => $author->getMorphClass(),
            'author_id' => $author->getKey(),
        ]);
    }
}
