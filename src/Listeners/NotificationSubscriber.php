<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Selli\Ticketing\Contracts\NotificationPreferences;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Events\ParticipantAdded;
use Selli\Ticketing\Events\SlaBreached;
use Selli\Ticketing\Events\SlaThresholdReached;
use Selli\Ticketing\Events\TicketAssigned;
use Selli\Ticketing\Notifications\NotificationThrottle;
use Selli\Ticketing\Notifications\ParticipantAddedNotification;
use Selli\Ticketing\Notifications\RecipientResolver;
use Selli\Ticketing\Notifications\ReplyPostedNotification;
use Selli\Ticketing\Notifications\SlaNotification;
use Selli\Ticketing\Notifications\TicketAssignedNotification;
use Selli\Ticketing\Notifications\TicketNotification;

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
            $this->dispatch([$event->assignee], fn (): TicketNotification => new TicketAssignedNotification($event->ticket));
        }
    }

    public function onMessage(MessagePosted $event): void
    {
        $author = $event->message->author;

        // A public reply reaches the requester too; an internal note stays with
        // the agents/collaborators. The assignee is included either way.
        $roles = $event->message->visibility === MessageVisibility::Public
            ? [ParticipantRole::Requester->value, ParticipantRole::Assignee->value, ParticipantRole::Collaborator->value, ParticipantRole::Watcher->value, ParticipantRole::Cc->value]
            : [ParticipantRole::Assignee->value, ParticipantRole::Collaborator->value, ParticipantRole::Watcher->value];

        $recipients = $this->recipients->participants(
            $event->ticket,
            $roles,
            $author instanceof Model ? $author : null,
        );

        $this->dispatch($recipients, fn (): TicketNotification => new ReplyPostedNotification($event->ticket, $event->message));
    }

    public function onParticipantAdded(ParticipantAdded $event): void
    {
        $model = $event->participant->participant;

        // The assignee participant is announced by TicketAssigned already; don't
        // double-notify them on first assignment.
        if ($model instanceof Model
            && $event->participant->notify
            && $event->participant->role !== ParticipantRole::Assignee) {
            $role = $event->participant->role->value;
            $this->dispatch([$model], fn (): TicketNotification => new ParticipantAddedNotification($event->ticket, $role));
        }
    }

    public function onSlaBreached(SlaBreached $event): void
    {
        $assignee = $this->recipients->assignee($event->ticket);

        if ($assignee instanceof Model) {
            $this->dispatch([$assignee], fn (): TicketNotification => new SlaNotification($event->ticket, $event->clock, breached: true));
        }
    }

    public function onSlaThreshold(SlaThresholdReached $event): void
    {
        $assignee = $this->recipients->assignee($event->ticket);

        if ($assignee instanceof Model) {
            $this->dispatch([$assignee], fn (): TicketNotification => new SlaNotification($event->ticket, $event->clock, breached: false));
        }
    }

    /**
     * Send a notification to each recipient on the channels they prefer, after
     * the digest throttle. Resolving channels here (not in via()) keeps the
     * throttle off the queue-retry path.
     *
     * @param  list<Model>  $recipients
     * @param  Closure(): TicketNotification  $make
     */
    protected function dispatch(array $recipients, Closure $make): void
    {
        /** @var NotificationPreferences $preferences */
        $preferences = app(NotificationPreferences::class);

        foreach ($recipients as $recipient) {
            $notification = $make();

            $channels = $preferences->channels($recipient, $notification->key(), $notification->supportedChannels());
            $channels = NotificationThrottle::filter($recipient, $notification->key(), $channels, $notification->ticket->getKey());

            if ($channels === []) {
                continue;
            }

            NotificationFacade::send([$recipient], $notification->onlyChannels($channels));
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
