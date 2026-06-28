<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Events\StateTransitioned;
use Selli\Ticketing\Events\TicketClosed;
use Selli\Ticketing\Events\TicketReopened;
use Selli\Ticketing\Events\TicketResolved;
use Selli\Ticketing\Exceptions\TransitionNotAllowedException;
use Selli\Ticketing\Exceptions\UnknownTransitionException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\TicketActivity;

it('moves a ticket through its workflow states', function (): void {
    $ticket = Ticketing::open(type: 'incident', title: 'Outage', requester: makeUser());
    expect($ticket->status)->toBe('new');

    Ticketing::for($ticket)->transition('triage');
    expect($ticket->fresh()->status)->toBe('triaged');

    Ticketing::for($ticket)->transition('start');
    expect($ticket->fresh()->status)->toBe('in_progress');
});

it('stamps resolved_at and emits TicketResolved on resolution', function (): void {
    Event::fake([StateTransitioned::class, TicketResolved::class]);

    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());
    Ticketing::for($ticket)->transition('resolve', note: 'Fixed it');

    $ticket = $ticket->fresh();

    expect($ticket->status)->toBe('resolved')
        ->and($ticket->resolved_at)->not->toBeNull();

    Event::assertDispatched(StateTransitioned::class);
    Event::assertDispatched(TicketResolved::class);
});

it('stamps closed_at and emits TicketClosed on terminal transition', function (): void {
    Event::fake([TicketClosed::class]);

    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());
    Ticketing::for($ticket)->transition('resolve');
    Ticketing::for($ticket)->transition('close');

    $ticket = $ticket->fresh();

    expect($ticket->status)->toBe('closed')
        ->and($ticket->closed_at)->not->toBeNull();

    Event::assertDispatched(TicketClosed::class);
});

it('reopens a resolved ticket, clearing timestamps and counting reopens', function (): void {
    Event::fake([TicketReopened::class]);

    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());
    Ticketing::for($ticket)->transition('resolve');
    Ticketing::for($ticket)->transition('reopen');

    $ticket = $ticket->fresh();

    expect($ticket->status)->toBe('open')
        ->and($ticket->resolved_at)->toBeNull()
        ->and($ticket->closed_at)->toBeNull()
        ->and($ticket->reopened_count)->toBe(1);

    Event::assertDispatched(TicketReopened::class);
});

it('enforces a transition guard', function (): void {
    $ticket = Ticketing::open(type: 'incident', title: 'Outage', requester: makeUser());
    Ticketing::for($ticket)->transition('triage');
    Ticketing::for($ticket)->transition('start');

    // incident.resolve requires a resolution note.
    expect(fn () => Ticketing::for($ticket)->transition('resolve'))
        ->toThrow(TransitionNotAllowedException::class);

    Ticketing::for($ticket)->transition('resolve', note: 'Root cause fixed');
    expect($ticket->fresh()->status)->toBe('resolved');
});

it('rejects a transition that is invalid from the current state', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());

    // 'reopen' is only valid from resolved/closed, not from the initial open state.
    Ticketing::for($ticket)->transition('reopen');
})->throws(TransitionNotAllowedException::class);

it('rejects an unknown transition', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());

    Ticketing::for($ticket)->transition('teleport');
})->throws(UnknownTransitionException::class);

it('records an audit entry for each transition', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());
    Ticketing::for($ticket)->transition('resolve', note: 'done');

    $activity = TicketActivity::query()
        ->where('ticket_id', $ticket->getKey())
        ->where('event', 'ticket.transitioned')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->changes['status']['to'])->toBe('resolved')
        ->and($activity->context['note'])->toBe('done');
});
