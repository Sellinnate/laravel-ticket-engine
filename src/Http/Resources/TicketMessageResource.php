<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Selli\Ticketing\Models\TicketMessage;

/**
 * @mixin TicketMessage
 */
class TicketMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'body' => $this->body,
            'visibility' => $this->visibility->value,
            'body_format' => $this->body_format->value,
            'source' => $this->source->value,
            'author' => [
                'type' => $this->author_type,
                'id' => $this->author_id,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
