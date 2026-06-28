<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\TicketMerged;
use Selli\Ticketing\Events\TicketOpened;
use Selli\Ticketing\Events\TicketSplit;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Tenancy\TenantContext;

it('merges source tickets into a target', function (): void {
    Event::fake([TicketMerged::class]);

    $target = Ticketing::open(type: 'support', title: 'Target', requester: makeUser());
    $source = Ticketing::open(type: 'support', title: 'Dup', requester: makeUser());
    Ticketing::for($source)->postMessage(makeUser(), 'from the duplicate');

    Ticketing::for($target)->mergeFrom([$source]);

    expect($target->messages()->count())->toBe(1)
        ->and(Ticket::query()->find($source->getKey()))->toBeNull() // soft-deleted
        ->and(Ticket::query()->withTrashed()->find($source->getKey())->trashed())->toBeTrue();

    Event::assertDispatched(TicketMerged::class);
});

it('refuses to merge tickets across tenants', function (): void {
    $context = app(TenantContext::class);
    $target = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'T', requester: makeUser(['tenant_id' => 5])));
    $source = $context->forTenant(9, fn () => Ticketing::open(type: 'support', title: 'S', requester: makeUser(['tenant_id' => 9])));

    Ticketing::for($target)->mergeFrom([$source]);
})->throws(CrossTenantException::class);

it('splits messages out of a ticket into a new one', function (): void {
    Event::fake([TicketSplit::class]);

    $source = Ticketing::open(type: 'support', title: 'Mixed', requester: makeUser());
    $m1 = Ticketing::for($source)->postMessage(makeUser(), 'keep this');
    $m2 = Ticketing::for($source)->postMessage(makeUser(), 'different request');

    $created = Ticketing::for($source)->split([$m2->getKey()], 'Different request');

    expect($created->messages()->count())->toBe(1)
        ->and((string) TicketMessage::query()->find($m2->getKey())->ticket_id)->toBe((string) $created->getKey())
        ->and((string) TicketMessage::query()->find($m1->getKey())->ticket_id)->toBe((string) $source->getKey())
        ->and($created->links()->count())->toBe(1); // linked back to source

    Event::assertDispatched(TicketSplit::class);
});

it('starts the split ticket fresh at the initial state and fires TicketOpened', function (): void {
    Event::fake([TicketOpened::class]);

    $source = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $message = Ticketing::for($source)->postMessage(makeUser(), 'msg');
    Ticketing::for($source)->transition('resolve'); // source becomes resolved

    $created = Ticketing::for($source)->split([$message->getKey()]);

    expect($created->status)->toBe('open') // initial state, not the source's 'resolved'
        ->and($created->resolved_at)->toBeNull();

    Event::assertDispatched(TicketOpened::class, fn (TicketOpened $e): bool => $e->ticket->is($created));
});

it('refuses to split with no matching messages', function (): void {
    $source = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::for($source)->split([999999]); // no such message on the source
})->throws(InvalidArgumentException::class);

it('carries the requester onto the split ticket', function (): void {
    $requester = makeUser();
    $source = Ticketing::open(type: 'support', title: 'x', requester: $requester);
    $message = Ticketing::for($source)->postMessage(makeUser(), 'msg');

    $created = Ticketing::for($source)->split([$message->getKey()]);

    expect($created->participants()
        ->where('role', ParticipantRole::Requester->value)
        ->where('participant_id', (string) $requester->getKey())
        ->exists())->toBeTrue();
});
