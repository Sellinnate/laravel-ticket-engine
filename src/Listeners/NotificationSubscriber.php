<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Events\ParticipantAdded;
use Selli\Ticketing\Events\SlaBreached;
use Selli\Ticketing\Events\SlaThresholdReached;
use Selli\Ticketing\Events\TicketAssigned;
use Selli\Ticketing\Notifications\ParticipantAddedNotification;
use Selli\Ticketing\Notifications\RecipientResolver;
use Selli\Ticketing\Notifications\ReplyPostedNotification;
use Selli\Ticketing\Notifications\SlaNotification;
use Selli\Ticketing\Notifications\TicketAssignedNotification;

/**
 * Turns domain events into Laravel notifications to the relevant ticket actors.
 * Channel selection (and digesting) happens inside each notification, so this
 * only resolves WHO to notify.
 */
class NotificationSubscriber
{
    public function __construct(protected RecipientResolver $recipients) {}

    public function onAssigned(TicketAssigned $event): void
    {
        if ($event->assignee instanceof Model) {
            $this->send([$event->assignee], new TicketAssignedNotification($event->ticket));
        }
    }

    public function onMessage(MessagePosted $event): void
    {
        $author = $event->message->author;

        // A public reply reaches the requester too; an internal note stays with
        // the agents/collaborators.
        $roles = $event->message->visibility === MessageVisibility::Public
            ? [ParticipantRole::Requester->value, ParticipantRole::Collaborator->value, ParticipantRole::Watcher->value, ParticipantRole::Cc->value]
            : [ParticipantRole::Collaborator->value, ParticipantRole::Watcher->value];

        $recipients = $this->recipients->participants(
            $event->ticket,
            $roles,
            $author instanceof Model ? $author : null,
        );

        $this->send($recipients, new ReplyPostedNotification($event->ticket, $event->message));
    }

    public function onParticipantAdded(ParticipantAdded $event): void
    {
        $model = $event->participant->participant;

        if ($model instanceof Model && $event->participant->notify) {
            $this->send([$model], new ParticipantAddedNotification($event->ticket, $event->participant->role->value));
        }
    }

    public function onSlaBreached(SlaBreached $event): void
    {
        $assignee = $this->recipients->assignee($event->ticket);

        if ($assignee instanceof Model) {
            $this->send([$assignee], new SlaNotification($event->ticket, $event->clock, breached: true));
        }
    }

    public function onSlaThreshold(SlaThresholdReached $event): void
    {
        $assignee = $this->recipients->assignee($event->ticket);

        if ($assignee instanceof Model) {
            $this->send([$assignee], new SlaNotification($event->ticket, $event->clock, breached: false));
        }
    }

    /**
     * @param  list<Model>  $recipients
     */
    protected function send(array $recipients, Notification $notification): void
    {
        if ($recipients !== []) {
            NotificationFacade::send($recipients, $notification);
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            TicketAssigned::class => 'onAssigned',
            MessagePosted::class => 'onMessage',
            ParticipantAdded::class => 'onParticipantAdded',
            SlaBreached::class => 'onSlaBreached',
            SlaThresholdReached::class => 'onSlaThreshold',
        ];
    }
}
