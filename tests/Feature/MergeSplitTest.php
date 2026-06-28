<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Events\TicketMerged;
use Selli\Ticketing\Events\TicketSplit;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;

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
