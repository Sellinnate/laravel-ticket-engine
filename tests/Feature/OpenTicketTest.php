<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Actions\OpenTicket;
use Selli\Ticketing\Data\OpenTicketData;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Events\TicketOpened;
use Selli\Ticketing\Exceptions\UnknownTicketTypeException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketActivity;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Tenancy\TenantContext;

it('opens a free-standing ticket via the facade', function (): void {
    $requester = makeUser();

    $ticket = Ticketing::open(
        type: 'support',
        title: 'Login is broken',
        requester: $requester,
        priority: Priority::High,
    );

    expect($ticket)->toBeInstanceOf(Ticket::class)
        ->and($ticket->title)->toBe('Login is broken')
        ->and($ticket->priority)->toBe(Priority::High)
        ->and($ticket->status)->toBe('open')
        ->and($ticket->subject_id)->toBeNull()
        ->and($ticket->reference)->toStartWith('SUPPORT-');
});

it('provisions the ticket type from config on first use', function (): void {
    expect(TicketType::query()->where('key', 'incident')->exists())->toBeFalse();

    $ticket = Ticketing::open(type: 'incident', title: 'Outage', requester: makeUser());

    expect(TicketType::query()->where('key', 'incident')->exists())->toBeTrue()
        ->and($ticket->status)->toBe('new'); // incident workflow initial state
});

it('applies the ticket type default priority when none is given', function (): void {
    // The incident type is configured with default_priority 30 (High).
    $ticket = Ticketing::open(type: 'incident', title: 'Outage', requester: makeUser());

    expect($ticket->priority)->toBe(Priority::High);
});

it('does not let extra attributes smuggle in a tenant', function (): void {
    $ticket = app(TenantContext::class)->forTenant(3, fn () => Ticketing::open(
        type: 'support',
        title: 'Scoped',
        requester: makeUser(['tenant_id' => 3]),
        attributes: ['tenant_id' => 999],
    ));

    expect($ticket->tenant_id)->toBe(3);
});

it('registers the requester as a participant', function (): void {
    $requester = makeUser(['name' => 'Ada']);

    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: $requester);

    $participant = $ticket->participants()->first();

    expect($participant)->not->toBeNull()
        ->and($participant->role)->toBe(ParticipantRole::Requester)
        ->and($participant->participant_id)->toBe((string) $requester->getKey());
});

it('opens a ticket about a subject through the host helper', function (): void {
    $order = makeOrder(['number' => '1001']);

    $ticket = $order->openTicket(type: 'incident', title: 'Missing shipment');

    expect($ticket->subject_type)->toBe($order->getMorphClass())
        ->and((string) $ticket->subject_id)->toBe((string) $order->getKey())
        ->and($order->tickets()->count())->toBe(1);
});

it('writes an immutable audit entry when opening', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());

    $activity = TicketActivity::query()->where('ticket_id', $ticket->getKey())->first();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('ticket.opened');
});

it('dispatches the TicketOpened event', function (): void {
    Event::fake([TicketOpened::class]);

    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());

    Event::assertDispatched(TicketOpened::class, fn (TicketOpened $e): bool => $e->ticket->is($ticket));
});

it('generates sequential references per type and year', function (): void {
    $first = Ticketing::open(type: 'support', title: 'One', requester: makeUser());
    $second = Ticketing::open(type: 'support', title: 'Two', requester: makeUser());

    $year = date('Y');

    expect($first->reference)->toBe("SUPPORT-{$year}-00001")
        ->and($second->reference)->toBe("SUPPORT-{$year}-00002");
});

it('throws for an unknown ticket type', function (): void {
    Ticketing::open(type: 'does-not-exist', title: 'X', requester: makeUser());
})->throws(UnknownTicketTypeException::class);

it('opens within an explicit tenant when provided', function (): void {
    $data = new OpenTicketData(
        type: 'support',
        title: 'Explicit tenant',
        requester: makeUser(['tenant_id' => 5]),
        tenantId: 5,
    );

    $ticket = app(OpenTicket::class)->handle($data);

    expect($ticket->tenant_id)->toBe(5);
});
