<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\ParticipantAdded;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\SlaClock;
use Selli\Ticketing\Models\SlaPolicy;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Notifications\NotificationThrottle;
use Selli\Ticketing\Notifications\ParticipantAddedNotification;
use Selli\Ticketing\Notifications\ReplyPostedNotification;
use Selli\Ticketing\Notifications\SlaNotification;
use Selli\Ticketing\Notifications\TicketAssignedNotification;
use Selli\Ticketing\Sla\SlaManager;

// Notifications are opt-in (no channels by default); activate them for the suite
// and disable the throttle so cross-test cache markers don't interfere.
beforeEach(function (): void {
    config()->set('ticketing.notifications.default_channels', ['mail', 'database']);
    config()->set('ticketing.notifications.throttle.seconds', 0);
});

afterEach(fn () => Carbon::setTestNow());

it('notifies the assignee on assignment', function (): void {
    Notification::fake();
    $agent = makeUser();
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::assign($ticket, assignee: $agent);

    Notification::assertSentTo($agent, TicketAssignedNotification::class);
});

it('notifies the requester on a public reply but not the agent author', function (): void {
    Notification::fake();
    $requester = makeUser();
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: $requester);
    $agent = makeUser();

    Ticketing::for($ticket)->postMessage($agent, 'here is an update');

    Notification::assertSentTo($requester, ReplyPostedNotification::class);
    Notification::assertNotSentTo($agent, ReplyPostedNotification::class);
});

it('does not notify the requester on an internal note', function (): void {
    Notification::fake();
    $requester = makeUser();
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: $requester);

    Ticketing::for($ticket)->postMessage(makeUser(), 'internal only', MessageVisibility::Internal);

    Notification::assertNotSentTo($requester, ReplyPostedNotification::class);
});

it('notifies a newly added participant', function (): void {
    Notification::fake();
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $collaborator = makeUser();

    $participant = TicketParticipant::query()->create(array_merge($ticket->tenantAttributes(), [
        'ticket_id' => $ticket->getKey(),
        'participant_type' => $collaborator->getMorphClass(),
        'participant_id' => $collaborator->getKey(),
        'role' => ParticipantRole::Collaborator->value,
        'notify' => true,
    ]));

    ParticipantAdded::dispatch($ticket, $participant);

    Notification::assertSentTo($collaborator, ParticipantAddedNotification::class);
});

it('notifies the assignee on an SLA breach', function (): void {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-29 10:00:00', 'UTC'));
    SlaPolicy::factory()->create(['first_response_minutes' => null, 'resolution_minutes' => 60]);
    $agent = makeUser();
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Ticketing::assign($ticket, assignee: $agent);

    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'UTC')); // past the 60m deadline
    app(SlaManager::class)->sweep();

    Notification::assertSentTo($agent, SlaNotification::class);
});

it('honours configured channel preferences', function (): void {
    config()->set('ticketing.notifications.events.ticket.assigned', ['mail']);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    $channels = (new TicketAssignedNotification($ticket))->via(makeUser());

    expect($channels)->toBe(['mail']);
});

it('digests a throttled channel within the window', function (): void {
    config()->set('ticketing.notifications.throttle.seconds', 300);
    config()->set('ticketing.notifications.throttle.channels', ['mail']);
    $agent = makeUser();

    $first = NotificationThrottle::filter($agent, 'ticket.assigned', ['mail', 'database'], 1);
    $second = NotificationThrottle::filter($agent, 'ticket.assigned', ['mail', 'database'], 1);

    expect($first)->toBe(['mail', 'database'])  // first pass: both delivered
        ->and($second)->toBe(['database']);      // mail digested, in-app still delivered
});

it('does not double-notify the assignee on first assignment', function (): void {
    Notification::fake();
    $agent = makeUser();
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::assign($ticket, assignee: $agent);

    Notification::assertSentTo($agent, TicketAssignedNotification::class);
    Notification::assertNotSentTo($agent, ParticipantAddedNotification::class);
});

it('renders the per-channel payloads', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());
    $notification = new TicketAssignedNotification($ticket);
    $user = makeUser();

    $array = $notification->toArray($user);
    $broadcast = $notification->toBroadcast($user);

    expect($array['key'])->toBe('ticket.assigned')
        ->and($array['reference'])->toBe($ticket->reference)
        ->and($broadcast->data['key'])->toBe('ticket.assigned')
        ->and($notification->toSlack($user))->toContain($ticket->reference)
        ->and($notification->toMail($user)->subject)->toContain('Ticket assigned');
});

it('renders reply and participant-added content', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $message = Ticketing::for($ticket)->postMessage(makeUser(), 'a fairly long reply body to summarise');

    $reply = new ReplyPostedNotification($ticket, $message);
    $added = new ParticipantAddedNotification($ticket, 'collaborator');

    expect($reply->key())->toBe('ticket.reply')
        ->and($reply->title())->toContain($ticket->reference)
        ->and($reply->body())->toContain('long reply body')
        ->and($added->key())->toBe('ticket.participant_added')
        ->and($added->title())->toContain($ticket->reference)
        ->and($added->body())->toContain('collaborator');
});

it('renders SLA notification content for breach and threshold', function (): void {
    SlaPolicy::factory()->create(['first_response_minutes' => 60]);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $clock = SlaClock::query()->where('ticket_id', $ticket->getKey())->firstOrFail();

    $breached = new SlaNotification($ticket, $clock, breached: true);
    $threshold = new SlaNotification($ticket, $clock, breached: false);

    expect($breached->key())->toBe('sla.breached')
        ->and($breached->title())->toContain('breached')
        ->and($breached->body())->toContain('breached')
        ->and($threshold->key())->toBe('sla.threshold_reached')
        ->and($threshold->title())->toContain('approaching')
        ->and($threshold->body())->toContain('approaching');
});

it('skips slack when no webhook is configured', function (): void {
    config()->set('ticketing.notifications.events.ticket.assigned', ['slack']);
    config()->set('ticketing.notifications.slack.webhook', null);
    config()->set('ticketing.notifications.throttle.seconds', 0);
    Http::fake();

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    makeUser()->notifyNow(new TicketAssignedNotification($ticket));

    Http::assertNothingSent();
});

it('delivers slack via the webhook channel', function (): void {
    config()->set('ticketing.notifications.events.ticket.assigned', ['slack']);
    config()->set('ticketing.notifications.slack.webhook', 'https://hooks.slack.test/abc');
    config()->set('ticketing.notifications.throttle.seconds', 0);
    Http::fake(['*' => Http::response('', 200)]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    makeUser()->notifyNow(new TicketAssignedNotification($ticket));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.slack.test/abc' && str_contains((string) $request['text'], $ticket->reference));
});
