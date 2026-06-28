<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Selli\Ticketing\Broadcasting\Channels;
use Selli\Ticketing\Listeners\BroadcastSubscriber;
use Selli\Ticketing\Models\Ticket;

/**
 * A queued, minimal realtime notice of a ticket change. The payload is just the
 * ids + the delta (status, the message id, …); a subscribed client reloads the
 * full detail through the API. Dispatched by {@see BroadcastSubscriber}
 * off the domain events, so the domain events themselves stay broadcast-free.
 *
 * It carries SCALAR SNAPSHOTS (not the Ticket model) taken at dispatch time. A
 * queued ShouldBroadcast that held the model would, via SerializesModels,
 * re-fetch a fresh row when the job runs — so a rapid reassignment/transition
 * could route the broadcast to the wrong channel or ship an inconsistent
 * payload. The snapshot freezes exactly what changed.
 */
class TicketBroadcasted implements ShouldBroadcast
{
    use InteractsWithSockets;

    /** Everyone watching the ticket, including a requester. */
    public const AUDIENCE_ALL = 'all';

    /** Agent-facing only — skips the per-ticket channel a requester may watch. */
    public const AUDIENCE_AGENTS = 'agents';

    /** Queue connection the broadcast is pushed onto (null = app default). */
    public ?string $connection = null;

    /** Queue the broadcast is pushed onto (null = connection default). */
    public ?string $queue = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int|string $ticketId,
        public int|string|null $tenantId,
        public int|string|null $assigneeId,
        public string $reference,
        public string $status,
        public string $action,
        public array $payload = [],
        public string $audience = self::AUDIENCE_ALL,
    ) {
        $connection = config('ticketing.broadcasting.connection') ?? config('ticketing.queue.connection');
        $this->connection = is_string($connection) ? $connection : null;

        $queue = config('ticketing.broadcasting.queue') ?? config('ticketing.queue.queue');
        $this->queue = is_string($queue) ? $queue : null;
    }

    /**
     * Snapshot the channel-routing + payload scalars off a live ticket.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromTicket(Ticket $ticket, string $action, array $payload = [], string $audience = self::AUDIENCE_ALL): self
    {
        return new self(
            ticketId: $ticket->getKey(),
            tenantId: $ticket->getAttribute($ticket->getTenantColumn()),
            assigneeId: $ticket->assignee_id,
            reference: (string) $ticket->reference,
            status: (string) $ticket->status,
            action: $action,
            payload: $payload,
            audience: $audience,
        );
    }

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        // The per-ticket channel can have a requester listening, so internal /
        // agent-only changes never go there — only the tenant agent feeds.
        if ($this->audience === self::AUDIENCE_ALL) {
            $channels[] = new PrivateChannel(Channels::ticket($this->ticketId));
        }

        if ($this->tenantId !== null) {
            $channels[] = new PrivateChannel(Channels::tenantTickets($this->tenantId));

            if ($this->assigneeId !== null) {
                $channels[] = new PrivateChannel(Channels::agent($this->tenantId, $this->assigneeId));
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ticket.'.$this->action;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return array_merge([
            'ticket_id' => $this->ticketId,
            'reference' => $this->reference,
            'status' => $this->status,
            'action' => $this->action,
        ], $this->payload);
    }
}
