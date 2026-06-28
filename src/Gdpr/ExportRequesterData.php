<?php

declare(strict_types=1);

namespace Selli\Ticketing\Gdpr;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Models\SatisfactionRating;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;

/**
 * Builds a data-subject export for a requester: their tickets, the public
 * conversation on each, and any satisfaction rating they left. Internal agent
 * notes are excluded — they're the team's working notes, not the requester's
 * own data, and an export must not become a back-door to them.
 *
 * @phpstan-type ExportedTicket array{reference: string|null, title: string|null, status: string|null, category: string|null, created_at: string|null, messages: list<array<string, mixed>>, satisfaction: array<string, mixed>|null}
 */
class ExportRequesterData
{
    /**
     * @return list<ExportedTicket>
     */
    public function handle(Model $requester): array
    {
        $tickets = RequesterTickets::query($requester)
            ->with(['messages' => fn ($query) => $query->where('visibility', MessageVisibility::Public->value)])
            ->get();

        $ratings = $this->ratingsByTicket($tickets->modelKeys());

        return $tickets->map(fn (Ticket $ticket): array => [
            'reference' => $ticket->reference,
            'title' => $ticket->title,
            'status' => $ticket->status,
            'category' => $ticket->category,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'messages' => $ticket->messages->map(fn (TicketMessage $message): array => [
                'body' => $message->body,
                'source' => $message->source->value,
                'created_at' => $message->created_at?->toIso8601String(),
                'meta' => $message->meta,
            ])->all(),
            'satisfaction' => $ratings[(string) $ticket->getKey()] ?? null,
        ])->all();
    }

    /**
     * @param  array<int, int|string>  $ticketIds
     * @return array<string, array{rating: int|null, comment: string|null}>
     */
    protected function ratingsByTicket(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        return SatisfactionRating::query()->withoutTenancy()
            ->whereIn('ticket_id', $ticketIds)
            ->get()
            ->keyBy(fn (SatisfactionRating $rating): string => (string) $rating->ticket_id)
            ->map(fn (SatisfactionRating $rating): array => [
                'rating' => $rating->rating,
                'comment' => $rating->comment,
            ])
            ->all();
    }
}
