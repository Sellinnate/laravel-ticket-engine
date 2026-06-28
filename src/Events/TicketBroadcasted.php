<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Broadcasting\Channels;
use Selli\Ticketing\Listeners\BroadcastSubscriber;
use Selli\Ticketing\Models\Ticket;

/**
 * A queued, minimal realtime notice of a ticket change. The payload is just the
 * ids + the delta (status, the message id, …); a subscribed client reloads the
 * full detail through the API. Dispatched by {@see BroadcastSubscriber}
 * off the domain events, so the domain events themselves stay broadcast-free.
 */
class TicketBroadcasted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

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
        public Ticket $ticket,
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
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        // The per-ticket channel can have a requester listening, so internal /
        // agent-only changes never go there — only the tenant agent feeds.
        if ($this->audience === self::AUDIENCE_ALL) {
            $channels[] = new PrivateChannel(Channels::ticket((string) $this->ticket->getKey()));
        }

        $tenantId = $this->ticket->getAttribute($this->ticket->getTenantColumn());

        if ($tenantId !== null) {
            $channels[] = new PrivateChannel(Channels::tenantTickets($tenantId));

            if ($this->ticket->assignee_id !== null) {
                $channels[] = new PrivateChannel(Channels::agent($tenantId, $this->ticket->assignee_id));
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
            'ticket_id' => $this->ticket->getKey(),
            'reference' => $this->ticket->reference,
            'status' => $this->ticket->status,
            'action' => $this->action,
        ], $this->payload);
    }
}
