<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Selli\Ticketing\Contracts\InboundRequesterResolver;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\ContactAnonymized;
use Selli\Ticketing\Events\RequesterAnonymized;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Mail\InboundEmail;
use Selli\Ticketing\Models\Contact;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Notifications\ReplyPostedNotification;
use Selli\Ticketing\Tenancy\TenantContext;

it('remembers one contact per tenant and normalised email', function (): void {
    $first = Ticketing::contacts()->remember('Jane.Doe@Example.com ', 'Jane');
    $again = Ticketing::contacts()->remember('jane.doe@example.com');

    expect($again->getKey())->toBe($first->getKey())
        ->and($first->email)->toBe('jane.doe@example.com')
        ->and($first->name)->toBe('Jane')
        ->and(Contact::query()->count())->toBe(1);
});

it('keeps the same address in two tenants apart', function (): void {
    $context = app(TenantContext::class);

    $one = $context->forTenant(1, fn () => Ticketing::contacts()->remember('jane@example.com', 'Jane'));
    $two = $context->forTenant(2, fn () => Ticketing::contacts()->remember('jane@example.com', 'Jane'));

    expect($one->getKey())->not->toBe($two->getKey())
        ->and($one->tenant_id)->toBe(1)
        ->and($two->tenant_id)->toBe(2);

    // Each tenant only sees its own contact.
    expect($context->forTenant(1, fn () => Contact::query()->pluck('id')->all()))->toBe([$one->getKey()]);
});

it('de-duplicates shared (null-tenant) contacts too', function (): void {
    $a = Ticketing::contacts()->remember('shared@example.com');
    $b = Ticketing::contacts()->remember('SHARED@example.com');

    expect($a->getKey())->toBe($b->getKey())
        ->and($a->tenant_id)->toBeNull();
});

it('fills in a missing name but never overwrites an existing one', function (): void {
    $anonymous = Ticketing::contacts()->remember('jane@example.com');
    expect($anonymous->name)->toBeNull();

    $named = Ticketing::contacts()->remember('jane@example.com', 'Jane', ['locale' => 'it']);
    $spoofed = Ticketing::contacts()->remember('jane@example.com', 'Someone else');

    expect($named->name)->toBe('Jane')
        ->and($named->locale)->toBe('it')
        ->and($spoofed->name)->toBe('Jane');
});

it('rejects an invalid email and finds by normalised address', function (): void {
    expect(fn () => Ticketing::contacts()->remember('not-an-email'))->toThrow(InvalidArgumentException::class);

    $contact = Ticketing::contacts()->remember('jane@example.com');

    expect(Ticketing::contacts()->find('JANE@example.com')?->getKey())->toBe($contact->getKey())
        ->and(Ticketing::contacts()->find('nobody@example.com'))->toBeNull()
        ->and(Ticketing::contacts()->find('garbage'))->toBeNull();
});

it('marks a contact verified once', function (): void {
    $contact = Ticketing::contacts()->markVerified(Ticketing::contacts()->remember('jane@example.com'));
    $stamp = $contact->verified_at;

    expect($contact->isVerified())->toBeTrue()
        ->and(Ticketing::contacts()->markVerified($contact)->verified_at?->equalTo($stamp))->toBeTrue();
});

it('acts as a notifiable requester', function (): void {
    Notification::fake();
    config()->set('ticketing.notifications.default_channels', ['mail']);

    $contact = Ticketing::contacts()->remember('jane@example.com', 'Jane');
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: $contact);
    Ticketing::postMessage($ticket, makeUser(), 'We are on it');

    expect($contact->requesterLabel())->toBe('Jane')
        ->and($contact->requesterEmail())->toBe('jane@example.com')
        ->and($contact->routeNotificationForMail())->toBe('jane@example.com');

    Notification::assertSentTo($contact, ReplyPostedNotification::class);
});

it('labels a nameless contact by email, then by id', function (): void {
    $contact = Ticketing::contacts()->remember('jane@example.com');
    expect($contact->requesterLabel())->toBe('jane@example.com');

    $contact->forceFill(['email' => null])->save();
    expect($contact->requesterLabel())->toBe('Contact #'.$contact->getKey());
});

it('resolves inbound email senders to contacts by default', function (): void {
    config()->set('ticketing.mail.inbound.enabled', true);
    config()->set('ticketing.mail.inbound.routes', [['match' => '*', 'type' => 'support']]);

    $message = Ticketing::receiveEmail(InboundEmail::fromArray([
        'from' => 'Customer@Example.com',
        'from_name' => 'A Customer',
        'recipients' => ['support@example.com'],
        'subject' => 'Broken',
        'text' => 'It is broken.',
        'message_id' => '<a@example.com>',
    ]));

    $contact = Contact::query()->sole();
    $requester = TicketParticipant::query()->where('role', ParticipantRole::Requester->value)->sole();

    expect($message?->author_type)->toBe($contact->getMorphClass())
        ->and($contact->email)->toBe('customer@example.com')
        ->and($contact->name)->toBe('A Customer')
        ->and($requester->participant_id)->toBe((string) $contact->getKey());
});

it('can leave inbound senders unresolved when contacts are off', function (): void {
    config()->set('ticketing.contacts.inbound_email', false);
    config()->set('ticketing.mail.inbound.enabled', true);
    config()->set('ticketing.mail.inbound.routes', [['match' => '*', 'type' => 'support']]);
    app()->forgetInstance(InboundRequesterResolver::class);

    $message = Ticketing::receiveEmail(InboundEmail::fromArray([
        'from' => 'customer@example.com',
        'recipients' => ['support@example.com'],
        'subject' => 'Broken',
        'text' => 'It is broken.',
    ]));

    expect($message?->author_id)->toBeNull()
        ->and(Contact::query()->count())->toBe(0);
});

it('erases a contact and the copies of its data', function (): void {
    Event::fake([ContactAnonymized::class, RequesterAnonymized::class]);
    config()->set('ticketing.mail.inbound.enabled', true);
    config()->set('ticketing.mail.inbound.routes', [['match' => '*', 'type' => 'support']]);

    Ticketing::receiveEmail(InboundEmail::fromArray([
        'from' => 'jane@example.com',
        'from_name' => 'Jane',
        'recipients' => ['support@example.com'],
        'subject' => 'My data',
        'text' => 'Please help.',
        'message_id' => '<m1@example.com>',
    ]));

    $contact = Contact::query()->sole();
    $ticket = Ticketing::tickets()->get()->sole();
    Ticketing::aiTurnModel()::query()->create(array_merge($ticket->tenantAttributes(), [
        'ticket_id' => $ticket->getKey(),
        'trigger_key' => 'ticket_message:test',
        'mode' => 'auto',
        'driver' => 'fake',
        'question' => 'Please help, I am Jane',
    ]));

    $touched = Ticketing::contacts()->anonymize($contact);

    $contact->refresh();
    $message = $ticket->messages()->sole();

    expect($touched)->toBe(1)
        ->and($contact->email)->toBeNull()
        ->and($contact->name)->toBe('[anonymized]')
        ->and($contact->dedupe_key)->toBeNull()
        ->and($contact->isAnonymized())->toBeTrue()
        ->and($message->meta['from'])->toBe('[anonymized]')
        ->and(Ticketing::aiTurnModel()::query()->sole()->question)->toBeNull();

    Event::assertDispatched(ContactAnonymized::class);
    Event::assertDispatched(RequesterAnonymized::class);

    // The address is free again: a new message creates a fresh contact.
    expect(Ticketing::contacts()->remember('jane@example.com')->getKey())->not->toBe($contact->getKey());
});

it('exports a contact with its tickets and conversations', function (): void {
    $contact = Ticketing::contacts()->remember('jane@example.com', 'Jane');
    $ticket = Ticketing::open(type: 'support', title: 'Order missing', requester: $contact);
    Ticketing::postMessage($ticket, $contact, 'Where is my order?');

    ['key' => $key] = Ticketing::widgetKeys()->create('Site', ['*']);
    ['conversation' => $conversation] = Ticketing::conversations()->start($key);
    $conversation->forceFill(['contact_id' => $contact->getKey()])->save();
    Ticketing::conversationMessageModel()::query()->create([
        'conversation_id' => $conversation->getKey(),
        'role' => 'visitor',
        'body' => 'Hello?',
    ]);

    $export = Ticketing::contacts()->export($contact);

    expect($export['contact']['email'])->toBe('jane@example.com')
        ->and($export['tickets'])->toHaveCount(1)
        ->and($export['tickets'][0]['messages'][0]['body'])->toBe('Where is my order?')
        ->and($export['conversations'])->toHaveCount(1)
        ->and($export['conversations'][0]['messages'][0]['body'])->toBe('Hello?');
});

it('erases the AI excerpts of a contact\'s and a host user\'s widget conversations', function (): void {
    ['key' => $key] = Ticketing::widgetKeys()->create('Site', ['*']);

    $contact = Ticketing::contacts()->remember('jane@example.com', 'Jane');
    ['conversation' => $byContact] = Ticketing::conversations()->start($key);
    $byContact->forceFill(['contact_id' => $contact->getKey()])->save();

    $user = makeUser(['name' => 'Host']);
    ['conversation' => $byUser] = Ticketing::conversations()->start($key);
    $byUser->forceFill(['requester_type' => $user->getMorphClass(), 'requester_id' => $user->getKey()])->save();

    foreach ([$byContact, $byUser] as $i => $conversation) {
        Ticketing::conversationMessageModel()::query()->create(['conversation_id' => $conversation->getKey(), 'role' => 'visitor', 'body' => 'My secret question']);
        Ticketing::aiTurnModel()::query()->create([
            'conversation_id' => $conversation->getKey(),
            'trigger_key' => 'conversation_message:test'.$i,
            'mode' => 'auto',
            'driver' => 'fake',
            'question' => 'My secret question',
        ]);
    }

    Ticketing::contacts()->anonymize($contact);
    Ticketing::anonymiseRequester($user);

    expect(Ticketing::aiTurnModel()::query()->whereNotNull('question')->count())->toBe(0)
        ->and(Ticketing::conversationMessageModel()::query()->where('body', 'My secret question')->count())->toBe(0);
});

it('anonymizes any number of contacts of the same tenant', function (): void {
    $first = Ticketing::contacts()->remember('first@example.com');
    $second = Ticketing::contacts()->remember('second@example.com');

    Ticketing::contacts()->anonymize($first);
    Ticketing::contacts()->anonymize($second);

    expect(Ticketing::contactModel()::query()->whereNull('dedupe_key')->count())->toBe(2)
        ->and(Ticketing::contacts()->remember('first@example.com')->getKey())->not->toBe($first->getKey());
});
