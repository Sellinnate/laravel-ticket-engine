<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Selli\Ticketing\Models\Ticket;

/**
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'status' => $this->status,
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'category' => $this->category,
            'ticket_type_id' => $this->ticket_type_id,
            'subject' => [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
            ],
            'assignee' => [
                'type' => $this->assignee_type,
                'id' => $this->assignee_id,
            ],
            'team_id' => $this->team_id,
            'reopened_count' => $this->reopened_count,
            'first_response_at' => $this->first_response_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'messages' => TicketMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
