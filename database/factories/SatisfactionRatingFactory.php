<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Selli\Ticketing\Enums\CsatScale;
use Selli\Ticketing\Models\SatisfactionRating;
use Selli\Ticketing\Support\Ticketing;

/**
 * @extends Factory<SatisfactionRating>
 */
class SatisfactionRatingFactory extends Factory
{
    protected $model = SatisfactionRating::class;

    /**
     * Honour a host-overridden model (Ticketing::useSatisfactionRatingModel()).
     *
     * @return class-string<SatisfactionRating>
     */
    public function modelName(): string
    {
        /** @var class-string<SatisfactionRating> $model */
        $model = Ticketing::satisfactionRatingModel();

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
            'scale' => CsatScale::FiveStar->value,
            'rating' => null,
            'comment' => null,
            'requested_at' => now(),
            'submitted_at' => null,
        ];
    }

    public function submitted(int $rating = 5, ?string $comment = 'Great support'): static
    {
        return $this->state(fn (): array => [
            'rating' => $rating,
            'comment' => $comment,
            'submitted_at' => now(),
        ]);
    }
}
