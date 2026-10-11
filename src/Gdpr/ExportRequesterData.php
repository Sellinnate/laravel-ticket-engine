<?php

declare(strict_types=1);

namespace Selli\Ticketing\Gdpr;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Models\SatisfactionRating;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\Ticketing;

/**
 * Builds a data-subject export for a requester: their tickets, the public
 * conversation on each, and any satisfaction rating they left. Internal agent
 * notes are excluded — they're the team's working notes, not the requester's
 * own data, and an export must not become a back-door to them.
 *
 * @phpstan-type ExportedMessage array{body: string, source: string, created_at: string|null}
 * @phpstan-type ExportedTicket array{reference: string, title: string, status: string, category: string|null, created_at: string|null, messages: list<ExportedMessage>, satisfaction: array{rating: int|null, comment: string|null}|null}
 *
 * @internal
 */
class ExportRequesterData
{
    /**
     * @return list<ExportedTicket>
     */
    public function handle(Model $requester): array
    {
        $includeTrashedMessages = RequesterTickets::softDeletes(Ticketing::ticketMessageModel());

        $tickets = RequesterTickets::query($requester)
            // withoutTenancy + withTrashed on the eager load too, or public
            // messages on the requester's tickets in OTHER tenants — or on
            // soft-deleted threads — come back empty.
            ->with(['messages' => function ($query) use ($includeTrashedMessages): void {
                if ($includeTrashedMessages) {
                    $query->withTrashed();
                }

                $query->withoutTenancy()->where('visibility', MessageVisibility::Public->value);
            }])
            ->get();

        $ratings = $this->ratingsByTicket($tickets->modelKeys());

        return array_values($tickets->map(fn (Ticket $ticket): array => [
            'reference' => $ticket->reference,
            'title' => $ticket->title,
            'status' => $ticket->status,
            'category' => $ticket->category,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'messages' => array_values($ticket->messages->map(fn (TicketMessage $message): array => [
                'body' => $message->body,
                'source' => $message->source->value,
                'created_at' => $message->created_at?->toIso8601String(),
                // Raw meta is deliberately omitted: it holds technical fields and
                // denormalised PII (from/from_name) that don't belong in a
                // requester's own export.
            ])->all()),
            'satisfaction' => $ratings[(string) $ticket->getKey()] ?? null,
        ])->all());
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

        return Ticketing::satisfactionRatingModel()::query()->withoutTenancy()
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
