<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\TicketAttachment;
use Selli\Ticketing\Support\Ticketing;

/**
 * @extends Factory<TicketAttachment>
 */
class TicketAttachmentFactory extends Factory
{
    protected $model = TicketAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ticketModel = Ticketing::ticketModel();

        return [
            'attachable_type' => (new $ticketModel)->getMorphClass(),
            'attachable_id' => $ticketModel::factory(),
            'disk' => 'local',
            'path' => 'attachments/'.fake()->uuid().'.txt',
            'name' => fake()->word().'.txt',
            'mime' => 'text/plain',
            'size' => fake()->numberBetween(1, 100000),
            'checksum' => fake()->sha256(),
        ];
    }

    public function attachedTo(Model $attachable): static
    {
        return $this->state(fn (): array => [
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
        ]);
    }
}
