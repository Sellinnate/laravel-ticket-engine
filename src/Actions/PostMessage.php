<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;

/**
 * Appends a message to a ticket's conversation, stamping the first public agent
 * response when applicable, and emits {@see MessagePosted}.
 */
class PostMessage
{
    public function __construct(protected AuditLogger $audit) {}

    public function handle(PostMessageData $data): TicketMessage
    {
        $model = Ticketing::ticketMessageModel();

        $message = DB::transaction(function () use ($model, $data): TicketMessage {
            $attributes = [
                'ticket_id' => $data->ticket->getKey(),
                'visibility' => $data->visibility,
                'body' => $data->body,
                'body_format' => $data->bodyFormat,
                'source' => $data->source,
                'meta' => $data->meta === [] ? null : $data->meta,
            ];

            if ($data->author !== null) {
                $attributes['author_type'] = $data->author->getMorphClass();
                $attributes['author_id'] = $data->author->getKey();
            }

            /** @var TicketMessage $message */
            $message = $model::query()->create($attributes);

            $this->stampFirstResponse($data);

            $this->audit->record(
                ticket: $data->ticket,
                event: 'message.posted',
                actor: $data->author,
                context: [
                    'message_id' => $message->getKey(),
                    'visibility' => $data->visibility->value,
                ],
            );

            return $message;
        });

        MessagePosted::dispatch($data->ticket, $message);

        return $message;
    }

    /**
     * Stamp first_response_at the first time an agent replies publicly.
     *
     * The author must be an agent and must not be the ticket's requester — a
     * model that implements both contracts (the documented dual case) does not
     * trigger a "first response" by replying to its own request.
     */
    protected function stampFirstResponse(PostMessageData $data): void
    {
        if ($data->visibility !== MessageVisibility::Public) {
            return;
        }

        if ($data->ticket->first_response_at !== null) {
            return;
        }

        if (! $data->author instanceof CanActOnTickets) {
            return;
        }

        if ($this->isRequester($data)) {
            return;
        }

        $data->ticket->forceFill(['first_response_at' => now()])->save();
    }

    /**
     * Whether the message author is registered as the ticket's requester.
     */
    protected function isRequester(PostMessageData $data): bool
    {
        if ($data->author === null) {
            return false;
        }

        return $data->ticket->participants()
            ->where('role', ParticipantRole::Requester->value)
            ->where('participant_type', $data->author->getMorphClass())
            ->where('participant_id', $data->author->getKey())
            ->exists();
    }
}
