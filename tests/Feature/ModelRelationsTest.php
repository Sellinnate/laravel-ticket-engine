<?php

declare(strict_types=1);

use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketActivity;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Models\TicketType;

it('resolves ticket relations', function (): void {
    $order = makeOrder();
    $agent = makeUser(['name' => 'Agent']);
    $ticket = $order->openTicket(type: 'support', title: 'Hello', requester: $agent);
    $ticket->update(['assignee_type' => $agent->getMorphClass(), 'assignee_id' => $agent->getKey()]);

    Ticketing::for($ticket)->postMessage($agent, 'Hi');

    $ticket->refresh();

    expect($ticket->type)->toBeInstanceOf(TicketType::class)
        ->and($ticket->subject->is($order))->toBeTrue()
        ->and($ticket->assignee->is($agent))->toBeTrue()
        ->and($ticket->messages)->toHaveCount(1)
        ->and($ticket->participants)->toHaveCount(1)
        ->and($ticket->activities->count())->toBeGreaterThan(0);
});

it('scopes tickets by status', function (): void {
    Ticket::factory()->withStatus('open')->create();
    Ticket::factory()->withStatus('closed')->create();

    expect(Ticket::query()->withStatus(['open'])->count())->toBe(1);
});

it('resolves message relations and the type relation', function (): void {
    $author = makeUser();
    $message = TicketMessage::factory()->from($author)->create();

    expect($message->ticket)->toBeInstanceOf(Ticket::class)
        ->and($message->author->is($author))->toBeTrue()
        ->and($message->ticket->type->tickets()->count())->toBeGreaterThanOrEqual(1);
});

it('resolves participant relations and role scope', function (): void {
    $user = makeUser();
    $ticket = Ticket::factory()->create();
    $participant = TicketParticipant::factory()
        ->participant($user)
        ->role(ParticipantRole::Watcher)
        ->create(['ticket_id' => $ticket->getKey()]);

    expect($participant->ticket->is($ticket))->toBeTrue()
        ->and($participant->participant->is($user))->toBeTrue()
        ->and(TicketParticipant::query()->withRole(ParticipantRole::Watcher)->count())->toBe(1);
});

it('resolves activity relations', function (): void {
    $actor = makeUser();
    $ticket = Ticket::factory()->create();
    $activity = TicketActivity::factory()->create([
        'ticket_id' => $ticket->getKey(),
        'actor_type' => $actor->getMorphClass(),
        'actor_id' => $actor->getKey(),
    ]);

    expect($activity->ticket->is($ticket))->toBeTrue()
        ->and($activity->actor->is($actor))->toBeTrue();
});

it('uses the default ticketable label from the trait', function (): void {
    $order = makeOrder();

    expect($order->ticketableLabel())->toStartWith('TestOrder #');
});
