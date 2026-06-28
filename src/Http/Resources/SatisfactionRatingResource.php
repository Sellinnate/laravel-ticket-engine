<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Selli\Ticketing\Models\SatisfactionRating;

/**
 * @mixin SatisfactionRating
 */
class SatisfactionRatingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'scale' => $this->scale->value,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
        ];
    }
}
