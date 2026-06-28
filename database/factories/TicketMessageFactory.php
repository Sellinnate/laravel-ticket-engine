<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;

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
            'ticket_id' => Ticket::factory(),
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
