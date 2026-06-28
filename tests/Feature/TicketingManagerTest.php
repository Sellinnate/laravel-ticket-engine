<?php

declare(strict_types=1);

use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketActivity;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Support\Ticketing as TicketingManager;

afterEach(fn () => TicketingManager::flushModelBindings());

it('resolves the default model bindings', function (): void {
    expect(TicketingManager::ticketModel())->toBe(Ticket::class)
        ->and(TicketingManager::ticketTypeModel())->toBe(TicketType::class)
        ->and(TicketingManager::ticketMessageModel())->toBe(TicketMessage::class)
        ->and(TicketingManager::ticketParticipantModel())->toBe(TicketParticipant::class)
        ->and(TicketingManager::ticketActivityModel())->toBe(TicketActivity::class);
});

it('honours overridden model bindings', function (): void {
    $custom = new class extends Ticket {};

    TicketingManager::useTicketModel($custom::class);
    TicketingManager::useTicketTypeModel(TicketType::class);
    TicketingManager::useTicketMessageModel(TicketMessage::class);
    TicketingManager::useTicketParticipantModel(TicketParticipant::class);
    TicketingManager::useTicketActivityModel(TicketActivity::class);

    expect(TicketingManager::ticketModel())->toBe($custom::class);
});

it('toggles ULID configuration', function (): void {
    TicketingManager::useUlids();
    expect(config('ticketing.ids.type'))->toBe('ulid');

    TicketingManager::useUlids(false);
    expect(config('ticketing.ids.type'))->toBe('auto');
});

it('instantiates a bound model via make', function (): void {
    expect(TicketingManager::make(Ticket::class))->toBeInstanceOf(Ticket::class);
});

it('opens via the for() helper with a subject', function (): void {
    $order = makeOrder();

    $ticket = Ticketing::for($order)->open(type: 'support', title: 'Subject ticket');

    expect($ticket->subject->is($order))->toBeTrue();
});

it('throws when posting a message on a subject-bound pending ticket', function (): void {
    $order = makeOrder();

    Ticketing::for($order)->postMessage(makeUser(), 'nope');
})->throws(LogicException::class);
