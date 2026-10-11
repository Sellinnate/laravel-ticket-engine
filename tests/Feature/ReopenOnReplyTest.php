<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Ai\AiAnswer;
use Selli\Ticketing\Ai\AiManager;
use Selli\Ticketing\Ai\AiSource;
use Selli\Ticketing\Ai\Drivers\FakeAiDriver;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Events\TicketReopened;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Mail\InboundEmail;
use Selli\Ticketing\Models\Contact;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Tests\Fixtures\TestRequester;

function resolvedFor(mixed $requester, string $type = 'support'): Ticket
{
    $ticket = Ticketing::open(type: $type, title: 'Done', requester: $requester);

    if ($type === 'incident') {
        Ticketing::transition($ticket, 'triage');
        Ticketing::transition($ticket, 'start');
        Ticketing::transition($ticket, 'resolve', note: 'Fixed');
    } else {
        Ticketing::transition($ticket, 'resolve');
    }

    return $ticket->refresh();
}

it('reopens a resolved ticket when the requester replies in time', function (): void {
    Event::fake([TicketReopened::class]);
    $requester = TestRequester::query()->create(['name' => 'Customer']);
    $ticket = resolvedFor($requester);

    Ticketing::postMessage($ticket, $requester, 'Still broken!');

    expect($ticket->refresh()->status)->toBe('open')
        ->and($ticket->reopened_count)->toBe(1)
        ->and($ticket->activities()->where('event', 'ticket.transitioned')->latest('id')->first()?->context['transition'])->toBe('reopen');

    Event::assertDispatched(TicketReopened::class);
});

it('reopens through the type\'s own workflow', function (): void {
    $contact = Ticketing::contacts()->remember('jane@example.com');
    $ticket = resolvedFor($contact, 'incident');

    Ticketing::postMessage($ticket, $contact, 'Happened again');

    expect($ticket->refresh()->status)->toBe('in_progress');
});

it('leaves the ticket alone after the window, for agents, notes or when disabled', function (): void {
    $requester = TestRequester::query()->create(['name' => 'Customer']);

    $late = resolvedFor($requester);
    $this->travel(8)->days();
    Ticketing::postMessage($late, $requester, 'Late reply');
    expect($late->refresh()->status)->toBe('resolved');

    $byAgent = resolvedFor($requester);
    Ticketing::postMessage($byAgent, makeUser(), 'Agent follow-up');
    Ticketing::postMessage($byAgent, $requester, 'Internal?', MessageVisibility::Internal);
    expect($byAgent->refresh()->status)->toBe('resolved');

    config()->set('ticketing.workflow.reopen_on_reply.within_days', 0);
    Ticketing::postMessage($late, $requester, 'Any time now');
    expect($late->refresh()->status)->toBe('open');
});

it('hands a reopened AI-resolved ticket back to the AI', function (): void {
    config()->set('ticketing.ai.mode', 'auto');
    $driver = app(AiManager::class)->driver('fake');
    assert($driver instanceof FakeAiDriver);
    $driver->queueAnswer(new AiAnswer('First', [new AiSource('faq', '1', 'F')], 0.9))
        ->queueAnswer(new AiAnswer('Second', [new AiSource('faq', '1', 'F')], 0.9));

    $contact = Ticketing::contacts()->remember('jane@example.com');
    $ticket = Ticketing::open(type: 'support', title: 'Q', requester: $contact);
    Ticketing::postMessage($ticket, $contact, 'Question');
    Ticketing::transition($ticket->refresh(), 'resolve');
    expect($ticket->refresh()->ai_status)->toBe(AiStatus::Resolved);

    Ticketing::postMessage($ticket, $contact, 'One more thing');

    expect($ticket->refresh()->status)->toBe('open')
        ->and($ticket->ai_status)->toBe(AiStatus::Active)
        ->and($ticket->messages()->where('body', 'Second')->exists())->toBeTrue();
});

it('does not reopen for a contact who is not the requester', function (): void {
    $requester = TestRequester::query()->create(['name' => 'Customer']);
    $ticket = resolvedFor($requester);
    $cc = Ticketing::contacts()->remember('cc@example.com');

    Ticketing::postMessage($ticket, $cc, 'I was only in CC');

    expect($ticket->refresh()->status)->toBe('resolved');
});

it('reopens even when the caller kept a stale ticket instance', function (): void {
    $requester = TestRequester::query()->create(['name' => 'Customer']);
    $ticket = Ticketing::open(type: 'support', title: 'Stale', requester: $requester);
    Ticketing::transition($ticket, 'resolve');

    // $ticket still says "open" in memory.
    Ticketing::postMessage($ticket, $requester, 'Not fixed');

    expect($ticket->refresh()->status)->toBe('open')
        ->and($ticket->reopened_count)->toBe(1);
});

it('reopens a tenant ticket when the requester replies by email', function (): void {
    config()->set('ticketing.mail.inbound.enabled', true);
    config()->set('ticketing.mail.inbound.routes', [['match' => 'help@example.test', 'tenant' => 7, 'type' => 'support']]);
    config()->set('ticketing.notifications.default_channels', ['database']);

    $first = Ticketing::receiveEmail(InboundEmail::fromArray([
        'from' => 'jane@example.test', 'recipients' => ['help@example.test'], 'subject' => 'Printer',
        'text' => 'Broken.', 'message_id' => '<one@client.test>',
    ]));
    $ticket = Ticket::query()->withoutTenancy()->findOrFail($first?->ticket_id);
    app(TenantContext::class)->forTenant(7, fn () => Ticketing::transition($ticket, 'resolve'));

    // The reply runs with no ambient tenant, like an inbound webhook.
    $reply = Ticketing::receiveEmail(InboundEmail::fromArray([
        'from' => 'jane@example.test', 'recipients' => ['help@example.test'], 'subject' => 'Re: Printer',
        'text' => 'Still broken.', 'message_id' => '<two@client.test>', 'in_reply_to' => '<one@client.test>',
    ]));

    expect($reply?->ticket_id)->toBe($ticket->getKey())
        ->and($ticket->refresh()->status)->toBe('open')
        ->and($reply?->author)->toBeInstanceOf(Contact::class);
});
