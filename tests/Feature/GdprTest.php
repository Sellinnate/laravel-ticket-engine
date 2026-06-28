<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Events\RequesterAnonymized;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\TicketActivity;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Tests\Fixtures\TestRequester;

it('anonymises a requester’s denormalised personal data and keeps the ticket', function (): void {
    $requester = TestRequester::query()->create(['name' => 'Jane', 'email' => 'jane@example.test']);
    $ticket = Ticketing::open(type: 'support', title: 'Broken', requester: $requester);
    Ticketing::postMessage($ticket, $requester, 'hello there', meta: ['from' => 'jane@example.test', 'from_name' => 'Jane']);

    Event::fake([RequesterAnonymized::class]);

    $count = Ticketing::anonymiseRequester($requester);

    $message = $ticket->fresh()->messages()->first();

    expect($count)->toBe(1)
        ->and($message->meta['from'])->toBe('[anonymized]')
        ->and($message->meta['from_name'])->toBe('[anonymized]')
        ->and($message->body)->toBe('hello there') // content kept for statistics
        ->and($ticket->fresh())->not->toBeNull();

    Event::assertDispatched(RequesterAnonymized::class, fn (RequesterAnonymized $event): bool => $event->tickets === 1);

    // The anonymisation itself is audited.
    expect($ticket->fresh()->activities()->where('event', 'requester.anonymized')->exists())->toBeTrue();
});

it('anonymises and exports a ticket where the requester is the subject (not a participant)', function (): void {
    $requester = TestRequester::query()->create(['name' => 'Subj', 'email' => 'subj@example.test']);
    // for() makes the requester the ticket's SUBJECT, exercising that query branch.
    $ticket = Ticketing::for($requester)->open(type: 'support', title: 'About me', requester: makeUser());
    Ticketing::postMessage($ticket, $requester, 'hi', meta: ['from' => 'subj@example.test']);

    expect(Ticketing::exportRequesterData($requester))->toHaveCount(1)
        ->and(Ticketing::anonymiseRequester($requester))->toBe(1)
        ->and($ticket->fresh()->messages()->first()->meta['from'])->toBe('[anonymized]');
});

it('exports a requester’s tickets, public messages and rating but not internal notes', function (): void {
    $requester = TestRequester::query()->create(['name' => 'Owner', 'email' => 'owner@example.test']);
    $ticket = Ticketing::open(type: 'support', title: 'My issue', requester: $requester);
    Ticketing::postMessage($ticket, $requester, 'public question');
    Ticketing::postMessage($ticket, makeUser(), 'internal agent note', MessageVisibility::Internal);
    Ticketing::submitCsat($ticket, 5, 'great service');

    $export = Ticketing::exportRequesterData($requester);

    expect($export)->toHaveCount(1)
        ->and($export[0]['title'])->toBe('My issue')
        ->and($export[0]['messages'])->toHaveCount(1) // internal note excluded
        ->and($export[0]['messages'][0]['body'])->toBe('public question')
        ->and($export[0]['satisfaction']['rating'])->toBe(5)
        ->and($export[0]['satisfaction']['comment'])->toBe('great service');
});

it('anonymises old closed tickets via a retention rule', function (): void {
    config()->set('ticketing.gdpr.retention', [['type' => 'support', 'after_days' => 30, 'action' => 'anonymize']]);

    $requester = TestRequester::query()->create(['name' => 'Old', 'email' => 'old@example.test']);
    $ticket = Ticketing::open(type: 'support', title: 'old one', requester: $requester);
    Ticketing::postMessage($ticket, $requester, 'hi', meta: ['from' => 'old@example.test']);
    $ticket->forceFill(['closed_at' => now()->subDays(90)])->saveQuietly();

    $this->artisan('ticketing:prune')->assertSuccessful();

    expect($ticket->fresh()->messages()->first()->meta['from'])->toBe('[anonymized]')
        ->and($ticket->fresh())->not->toBeNull(); // anonymise keeps the ticket
});

it('deletes old closed tickets (and their rows) via a delete retention rule', function (): void {
    config()->set('ticketing.gdpr.retention', [['type' => '*', 'after_days' => 30, 'action' => 'delete']]);

    $requester = TestRequester::query()->create(['name' => 'Gone', 'email' => 'gone@example.test']);
    $ticket = Ticketing::open(type: 'support', title: 'to delete', requester: $requester);
    Ticketing::postMessage($ticket, $requester, 'hi');
    $ticket->forceFill(['closed_at' => now()->subDays(90)])->saveQuietly();
    $id = $ticket->getKey();

    $this->artisan('ticketing:prune')->assertSuccessful();

    // The ticket AND its child rows are gone — no orphaned PII.
    expect(Ticketing::ticketModel()::query()->withoutGlobalScopes()->find($id))->toBeNull()
        ->and(TicketMessage::query()->withoutGlobalScopes()->where('ticket_id', $id)->count())->toBe(0)
        ->and(TicketParticipant::query()->withoutGlobalScopes()->where('ticket_id', $id)->count())->toBe(0)
        ->and(TicketActivity::query()->withoutGlobalScopes()->where('ticket_id', $id)->count())->toBe(0);
});

it('does not touch a recently-closed ticket', function (): void {
    config()->set('ticketing.gdpr.retention', [['type' => '*', 'after_days' => 30, 'action' => 'delete']]);

    $ticket = Ticketing::open(type: 'support', title: 'recent', requester: makeUser());
    $ticket->forceFill(['closed_at' => now()->subDays(5)])->saveQuietly();

    $this->artisan('ticketing:prune')->assertSuccessful();

    expect(Ticketing::ticketModel()::query()->withoutGlobalScopes()->find($ticket->getKey()))->not->toBeNull();
});
