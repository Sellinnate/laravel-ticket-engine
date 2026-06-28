<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Events\PriorityChanged;
use Selli\Ticketing\Events\StateTransitioned;
use Selli\Ticketing\Events\TicketAssigned;
use Selli\Ticketing\Events\TicketBroadcasted;
use Selli\Ticketing\Events\TicketClosed;
use Selli\Ticketing\Events\TicketOpened;
use Selli\Ticketing\Events\TicketReopened;
use Selli\Ticketing\Events\TicketResolved;

/**
 * Turns the relevant domain events into a single queued, minimal
 * {@see TicketBroadcasted} on the package's private channels. Keeping this in a
 * subscriber (rather than making each domain event ShouldBroadcast) means
 * broadcasting is opt-in and selective, and the domain events stay usable by
 * the SLA/automation/notification listeners without dragging in a broadcaster.
 */
class BroadcastSubscriber
{
    public function onOpened(TicketOpened $event): void
    {
        TicketBroadcasted::dispatch($event->ticket, 'opened');
    }

    public function onTransitioned(StateTransitioned $event): void
    {
        TicketBroadcasted::dispatch($event->ticket, 'transitioned', [
            'from' => $event->from,
            'to' => $event->to,
            'transition' => $event->transition,
        ]);
    }

    public function onResolved(TicketResolved $event): void
    {
        TicketBroadcasted::dispatch($event->ticket, 'resolved');
    }

    public function onClosed(TicketClosed $event): void
    {
        TicketBroadcasted::dispatch($event->ticket, 'closed');
    }

    public function onReopened(TicketReopened $event): void
    {
        TicketBroadcasted::dispatch($event->ticket, 'reopened', [
            'from' => $event->from,
            'to' => $event->to,
        ]);
    }

    public function onAssigned(TicketAssigned $event): void
    {
        // Assignment is agent-facing — it never goes to the per-ticket channel a
        // requester may watch.
        TicketBroadcasted::dispatch($event->ticket, 'assigned', [
            'assignee_id' => $event->assignee?->getKey(),
            'team_id' => $event->team?->getKey(),
        ], TicketBroadcasted::AUDIENCE_AGENTS);
    }

    public function onPriorityChanged(PriorityChanged $event): void
    {
        TicketBroadcasted::dispatch($event->ticket, 'priority.changed', [
            'from' => $event->from->value,
            'to' => $event->to->value,
        ], TicketBroadcasted::AUDIENCE_AGENTS);
    }

    public function onMessagePosted(MessagePosted $event): void
    {
        // An internal note must not ping the per-ticket (requester-visible)
        // channel — only the agent feeds. The body is never broadcast; the
        // client reloads it through the API, which hides internal notes anyway.
        $audience = $event->message->visibility === MessageVisibility::Internal
            ? TicketBroadcasted::AUDIENCE_AGENTS
            : TicketBroadcasted::AUDIENCE_ALL;

        TicketBroadcasted::dispatch($event->ticket, 'message.posted', [
            'message_id' => $event->message->getKey(),
            'visibility' => $event->message->visibility->value,
        ], $audience);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            TicketOpened::class => 'onOpened',
            StateTransitioned::class => 'onTransitioned',
            TicketResolved::class => 'onResolved',
            TicketClosed::class => 'onClosed',
            TicketReopened::class => 'onReopened',
            TicketAssigned::class => 'onAssigned',
            PriorityChanged::class => 'onPriorityChanged',
            MessagePosted::class => 'onMessagePosted',
        ];
    }
}
