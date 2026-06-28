<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Broadcasting\Channels;
use Selli\Ticketing\Broadcasting\DefaultChannelAuthorizer;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\TicketBroadcasted;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Tests\Fixtures\TestRequester;

/**
 * @return list<string>
 */
function broadcastChannelNames(TicketBroadcasted $event): array
{
    return array_map(fn ($channel): string => $channel->name, $event->broadcastOn());
}

it('builds prefixed, scoped channel names', function (): void {
    expect(Channels::ticket(7))->toBe('ticketing.ticket.7')
        ->and(Channels::tenantTickets(5))->toBe('ticketing.tenant.5.tickets')
        ->and(Channels::agent(5, 9))->toBe('ticketing.tenant.5.agent.9');
});

it('broadcasts on the ticket, tenant and assignee channels for an all-audience event', function (): void {
    $context = app(TenantContext::class);
    $agent = makeUser(['tenant_id' => 5]);
    $ticket = $context->forTenant(5, function () use ($agent) {
        $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5]));

        return Ticketing::assign(ticket: $ticket, assignee: $agent);
    });

    $names = broadcastChannelNames(new TicketBroadcasted($ticket, 'opened'));

    expect($names)->toContain('private-'.Channels::ticket((string) $ticket->getKey()))
        ->toContain('private-'.Channels::tenantTickets(5))
        ->toContain('private-'.Channels::agent(5, $agent->getKey()));
});

it('keeps an agents-only event off the per-ticket channel', function (): void {
    $context = app(TenantContext::class);
    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5])));

    $names = broadcastChannelNames(new TicketBroadcasted($ticket, 'assigned', [], TicketBroadcasted::AUDIENCE_AGENTS));

    expect($names)->not->toContain('private-'.Channels::ticket((string) $ticket->getKey()))
        ->and($names)->toContain('private-'.Channels::tenantTickets(5));
});

it('carries a minimal id + delta payload and a stable event name', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    $event = new TicketBroadcasted($ticket, 'message.posted', ['message_id' => 7, 'visibility' => 'public']);

    expect($event->broadcastAs())->toBe('ticket.message.posted')
        ->and($event->broadcastWith())->toMatchArray([
            'ticket_id' => $ticket->getKey(),
            'status' => $ticket->status,
            'action' => 'message.posted',
            'message_id' => 7,
            'visibility' => 'public',
        ])
        ->and($event->broadcastWith())->toHaveKey('reference');
});

it('does not broadcast while broadcasting is disabled (the default)', function (): void {
    $captured = [];
    Event::listen(TicketBroadcasted::class, function (TicketBroadcasted $event) use (&$captured): void {
        $captured[] = $event;
    });

    Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect($captured)->toBeEmpty();
});

it('authorizes the tenant feed only for agents of that tenant', function (): void {
    $authorizer = app(DefaultChannelAuthorizer::class);
    $agent = makeUser(['tenant_id' => 5]);

    expect($authorizer->forTenantTickets($agent, 5))->toBeTrue()
        ->and($authorizer->forTenantTickets($agent, 9))->toBeFalse();

    // A requester-only account is not an agent, so the agent feed is denied.
    $requester = TestRequester::query()->create(['name' => 'R', 'tenant_id' => 5]);
    expect($authorizer->forTenantTickets($requester, 5))->toBeFalse();
});

it('authorizes an agent personal channel only for that same agent in that tenant', function (): void {
    $authorizer = app(DefaultChannelAuthorizer::class);
    $a = makeUser(['tenant_id' => 5]);
    $b = makeUser(['tenant_id' => 5]);

    expect($authorizer->forAgent($a, 5, $a->getKey()))->toBeTrue()
        ->and($authorizer->forAgent($a, 5, $b->getKey()))->toBeFalse()
        ->and($authorizer->forAgent($a, 9, $a->getKey()))->toBeFalse();
});

it('authorizes a ticket channel for its tenant agents and denies cross-tenant', function (): void {
    $context = app(TenantContext::class);
    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5])));
    $authorizer = app(DefaultChannelAuthorizer::class);

    $agent5 = makeUser(['tenant_id' => 5]);
    $this->actingAs($agent5);
    expect($authorizer->forTicket($agent5, $ticket->getKey()))->toBeTrue();

    $agent9 = makeUser(['tenant_id' => 9]);
    $this->actingAs($agent9);
    expect($authorizer->forTicket($agent9, $ticket->getKey()))->toBeFalse();
});

it('authorizes a ticket channel for its requester/subject and explicit participants', function (): void {
    $context = app(TenantContext::class);
    $requester = TestRequester::query()->create(['name' => 'Req', 'tenant_id' => 5]);
    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: $requester));

    $authorizer = app(DefaultChannelAuthorizer::class);

    // The subject (requester) may watch their own ticket though not an agent.
    $this->actingAs($requester);
    expect($authorizer->forTicket($requester, $ticket->getKey()))->toBeTrue();

    // A non-agent who is neither subject nor participant is denied...
    $stranger = TestRequester::query()->create(['name' => 'Stranger', 'tenant_id' => 5]);
    $this->actingAs($stranger);
    expect($authorizer->forTicket($stranger, $ticket->getKey()))->toBeFalse();

    // ...until added as an explicit participant.
    $context->forTenant(5, fn () => $ticket->participants()->create([
        'participant_type' => $stranger->getMorphClass(),
        'participant_id' => $stranger->getKey(),
        'role' => ParticipantRole::Watcher->value,
        'notify' => true,
    ]));
    expect($authorizer->forTicket($stranger, $ticket->getKey()))->toBeTrue();
});
