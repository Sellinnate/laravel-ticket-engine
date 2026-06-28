<?php

declare(strict_types=1);

namespace Selli\Ticketing\Automation;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Events\CsatSubmitted;
use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Events\ParticipantAdded;
use Selli\Ticketing\Events\PriorityChanged;
use Selli\Ticketing\Events\SlaBreached;
use Selli\Ticketing\Events\SlaThresholdReached;
use Selli\Ticketing\Events\StateTransitioned;
use Selli\Ticketing\Events\TicketAssigned;
use Selli\Ticketing\Events\TicketClosed;
use Selli\Ticketing\Events\TicketOpened;
use Selli\Ticketing\Events\TicketReopened;
use Selli\Ticketing\Events\TicketResolved;
use Selli\Ticketing\Models\Ticket;

/**
 * Maps the domain events that can trigger automation to stable string keys (used
 * in rule config), and pulls the ticket/actor out of each event uniformly.
 */
class TriggerRegistry
{
    /**
     * @return array<class-string, string>
     */
    public static function map(): array
    {
        return [
            TicketOpened::class => 'ticket.opened',
            TicketAssigned::class => 'ticket.assigned',
            StateTransitioned::class => 'ticket.transitioned',
            TicketResolved::class => 'ticket.resolved',
            TicketClosed::class => 'ticket.closed',
            TicketReopened::class => 'ticket.reopened',
            PriorityChanged::class => 'ticket.priority_changed',
            MessagePosted::class => 'message.posted',
            ParticipantAdded::class => 'participant.added',
            SlaBreached::class => 'sla.breached',
            SlaThresholdReached::class => 'sla.threshold_reached',
            CsatSubmitted::class => 'csat.submitted',
        ];
    }

    public static function keyFor(object $event): ?string
    {
        return self::map()[$event::class] ?? null;
    }

    public static function ticketOf(object $event): ?Ticket
    {
        return property_exists($event, 'ticket') && $event->ticket instanceof Ticket
            ? $event->ticket
            : null;
    }

    public static function actorOf(object $event): ?Model
    {
        return property_exists($event, 'actor') && $event->actor instanceof Model
            ? $event->actor
            : null;
    }
}
