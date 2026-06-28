<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Models\CannedResponse;
use Selli\Ticketing\Support\Ticketing;

/**
 * @extends Factory<CannedResponse>
 */
class CannedResponseFactory extends Factory
{
    protected $model = CannedResponse::class;

    /**
     * Honour a host-overridden model (Ticketing::useCannedResponseModel()).
     *
     * @return class-string<CannedResponse>
     */
    public function modelName(): string
    {
        /** @var class-string<CannedResponse> $model */
        $model = Ticketing::cannedResponseModel();

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'body' => 'Hello {{requester.name}}, re {{ticket.reference}}.',
            'ticket_type_id' => null,
            'is_active' => true,
        ];
    }
}
