<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Tenancy\TenantContext;

it('posts a public message to a ticket', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());
    $agent = makeUser(['name' => 'Agent']);

    $message = Ticketing::for($ticket)->postMessage($agent, 'On it', MessageVisibility::Public);

    expect($message)->toBeInstanceOf(TicketMessage::class)
        ->and($message->body)->toBe('On it')
        ->and($message->visibility)->toBe(MessageVisibility::Public)
        ->and((string) $message->author_id)->toBe((string) $agent->getKey());
});

it('stamps first_response_at on the first public agent reply', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());
    $agent = makeUser(['name' => 'Agent']);

    expect($ticket->first_response_at)->toBeNull();

    Ticketing::for($ticket)->postMessage($agent, 'Working on it');

    expect($ticket->fresh()->first_response_at)->not->toBeNull();
});

it('does not stamp first response when the requester replies to their own ticket', function (): void {
    // A dual-contract user (both requester and agent) opens and then replies.
    $user = makeUser();
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: $user);

    Ticketing::for($ticket)->postMessage($user, 'Any update?', MessageVisibility::Public);

    expect($ticket->fresh()->first_response_at)->toBeNull();
});

it('recognises the requester even without an ambient tenant context', function (): void {
    $context = app(TenantContext::class);

    // Tenant-scoped ticket whose requester is a dual-contract user.
    $user = makeUser(['tenant_id' => 6]);
    $ticket = $context->forTenant(6, fn () => Ticketing::open(type: 'support', title: 'Help', requester: $user));

    // Reply as the requester with NO ambient tenant context (queue/CLI flow).
    Ticketing::for($ticket)->postMessage($user, 'Any update?', MessageVisibility::Public);

    expect($ticket->fresh()->first_response_at)->toBeNull();
});

it('stamps first response even when the ambient context differs from the ticket tenant', function (): void {
    $context = app(TenantContext::class);

    $ticket = $context->forTenant(6, fn () => Ticketing::open(type: 'support', title: 'Help', requester: makeUser(['tenant_id' => 6])));
    $agent = makeUser(['name' => 'Agent', 'tenant_id' => 6]);

    // Reply as an agent with NO ambient tenant context (queue/CLI flow).
    Ticketing::for($ticket)->postMessage($agent, 'Working on it', MessageVisibility::Public);

    $persisted = Ticket::query()->withoutTenancy()->find($ticket->getKey());

    expect($persisted->first_response_at)->not->toBeNull();
});

it('does not stamp first_response_at for internal notes', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());
    $agent = makeUser(['name' => 'Agent']);

    Ticketing::for($ticket)->postMessage($agent, 'Internal note', MessageVisibility::Internal);

    expect($ticket->fresh()->first_response_at)->toBeNull();
});

it('separates public and internal messages via scopes', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());
    $agent = makeUser(['name' => 'Agent']);

    Ticketing::for($ticket)->postMessage($agent, 'Public reply', MessageVisibility::Public);
    Ticketing::for($ticket)->postMessage($agent, 'Secret', MessageVisibility::Internal);

    expect($ticket->messages()->public()->count())->toBe(1)
        ->and($ticket->messages()->internal()->count())->toBe(1);
});

it('dispatches MessagePosted', function (): void {
    Event::fake([MessagePosted::class]);

    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());
    Ticketing::for($ticket)->postMessage(makeUser(), 'Hi');

    Event::assertDispatched(MessagePosted::class);
});
