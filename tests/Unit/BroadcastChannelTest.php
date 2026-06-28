<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Broadcasting\Channels;
use Selli\Ticketing\Broadcasting\DefaultChannelAuthorizer;
use Selli\Ticketing\Contracts\TenantResolver;
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
        ->and(Channels::agent(5, 'App\\Models\\User', 9))->toBe('ticketing.tenant.5.agent.App-Models-User.9')
        ->and(Channels::agent(5, 'agent', 9))->toBe('ticketing.tenant.5.agent.agent.9');
});

it('broadcasts on the ticket, tenant and assignee channels for an all-audience event', function (): void {
    $context = app(TenantContext::class);
    $agent = makeUser(['tenant_id' => 5]);
    $ticket = $context->forTenant(5, function () use ($agent) {
        $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5]));

        return Ticketing::assign(ticket: $ticket, assignee: $agent);
    });

    $names = broadcastChannelNames(TicketBroadcasted::fromTicket($ticket, 'opened'));

    expect($names)->toContain('private-'.Channels::ticket((string) $ticket->getKey()))
        ->toContain('private-'.Channels::tenantTickets(5))
        ->toContain('private-'.Channels::agent(5, $agent->getMorphClass(), $agent->getKey()));
});

it('sends an agents-only event to the per-ticket agents channel, not the watcher channel', function (): void {
    $context = app(TenantContext::class);
    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5])));

    $names = broadcastChannelNames(TicketBroadcasted::fromTicket($ticket, 'assigned', [], TicketBroadcasted::AUDIENCE_AGENTS));

    expect($names)->not->toContain('private-'.Channels::ticket((string) $ticket->getKey()))
        ->and($names)->toContain('private-'.Channels::ticketAgents((string) $ticket->getKey()))
        ->and($names)->toContain('private-'.Channels::tenantTickets(5));
});

it('still delivers an agents-only event on a shared (null-tenant) ticket', function (): void {
    // No tenant context → a shared ticket with no tenant feed.
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    expect($ticket->getAttribute($ticket->getTenantColumn()))->toBeNull();

    $names = broadcastChannelNames(TicketBroadcasted::fromTicket($ticket, 'assigned', [], TicketBroadcasted::AUDIENCE_AGENTS));

    // Exactly the per-ticket agents channel — never an empty list.
    expect($names)->toBe(['private-'.Channels::ticketAgents((string) $ticket->getKey())]);
});

it('carries a minimal id + delta payload and a stable event name', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    $event = TicketBroadcasted::fromTicket($ticket, 'message.posted', ['message_id' => 7, 'visibility' => 'public']);

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

it('authorizes an agent personal channel only for that same agent (type + id) in that tenant', function (): void {
    $authorizer = app(DefaultChannelAuthorizer::class);
    $a = makeUser(['tenant_id' => 5]);
    $b = makeUser(['tenant_id' => 5]);
    $type = Channels::token($a->getMorphClass());

    expect($authorizer->forAgent($a, 5, $type, $a->getKey()))->toBeTrue()
        ->and($authorizer->forAgent($a, 5, $type, $b->getKey()))->toBeFalse()
        ->and($authorizer->forAgent($a, 9, $type, $a->getKey()))->toBeFalse()
        // A different morph type with the same id can't reach this agent's feed.
        ->and($authorizer->forAgent($a, 5, 'Some-Other-Model', $a->getKey()))->toBeFalse();
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

it('gates an agent on a shared (null-tenant) ticket by allow_shared', function (): void {
    // No tenant context → a shared, null-tenant ticket.
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    expect($ticket->getAttribute($ticket->getTenantColumn()))->toBeNull();

    $agent = makeUser(['tenant_id' => 5]);
    $this->actingAs($agent);

    // allow_shared on (default): the shared ticket is visible, so an agent passes.
    expect(app(DefaultChannelAuthorizer::class)->forTicket($agent, $ticket->getKey()))->toBeTrue();

    // allow_shared off: shared rows are hidden from scoped reads, so the agent is
    // denied the channel too — knowing the id isn't enough.
    app()->instance(TenantContext::class, new TenantContext(
        resolver: app(TenantResolver::class), enabled: true, column: 'tenant_id', allowShared: false,
    ));
    expect(app(DefaultChannelAuthorizer::class)->forTicket($agent, $ticket->getKey()))->toBeFalse();
});

it('denies a ticket channel for an unknown ticket id', function (): void {
    $agent = makeUser(['tenant_id' => 5]);
    $this->actingAs($agent);

    expect(app(DefaultChannelAuthorizer::class)->forTicket($agent, 999999))->toBeFalse();
});

it('authorizes a ticket channel for the morph subject', function (): void {
    $context = app(TenantContext::class);
    $subject = TestRequester::query()->create(['name' => 'Subj', 'tenant_id' => 5]);
    // The subject is the thing the ticket is "about" (the for() target), distinct
    // from the requester participant.
    $ticket = $context->forTenant(5, fn () => Ticketing::for($subject)->open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5])));

    $this->actingAs($subject);
    expect(app(DefaultChannelAuthorizer::class)->forTicket($subject, $ticket->getKey()))->toBeTrue();
});

it('authorizes the per-ticket agents feed for tenant agents only', function (): void {
    $context = app(TenantContext::class);
    $requester = TestRequester::query()->create(['name' => 'Req', 'tenant_id' => 5]);
    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: $requester));
    $authorizer = app(DefaultChannelAuthorizer::class);

    $agent5 = makeUser(['tenant_id' => 5]);
    $this->actingAs($agent5);
    expect($authorizer->forTicketAgents($agent5, $ticket->getKey()))->toBeTrue();

    // The requester (a participant on the watcher channel) is NOT on the agents feed.
    $this->actingAs($requester);
    expect($authorizer->forTicketAgents($requester, $ticket->getKey()))->toBeFalse();

    // A cross-tenant agent is denied.
    $agent9 = makeUser(['tenant_id' => 9]);
    $this->actingAs($agent9);
    expect($authorizer->forTicketAgents($agent9, $ticket->getKey()))->toBeFalse();
});

it('lets a null-tenant requester watch their own tenant-scoped ticket', function (): void {
    // A requester whose own tenant_id is null is still the subject of a ticket
    // that lives in a tenant — loading without the scope is what makes this work.
    $context = app(TenantContext::class);
    $requester = TestRequester::query()->create(['name' => 'R']);
    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: $requester));

    // Guard the premise: the ticket really is tenant-scoped (not shared), so the
    // test would catch a regression where open() stops propagating the tenant.
    expect((string) $ticket->getAttribute($ticket->getTenantColumn()))->toBe('5');

    $this->actingAs($requester);
    expect(app(DefaultChannelAuthorizer::class)->forTicket($requester, $ticket->getKey()))->toBeTrue();
});

it('authorizes tenant and agent feeds by role alone in single-tenant mode', function (): void {
    $agent = makeUser();

    // Rebind a tenancy-disabled context: there is no tenant to match on.
    app()->instance(TenantContext::class, new TenantContext(
        resolver: app(TenantResolver::class),
        enabled: false,
        column: 'tenant_id',
        allowShared: true,
    ));

    $authorizer = app(DefaultChannelAuthorizer::class);
    $type = Channels::token($agent->getMorphClass());

    expect($authorizer->forTenantTickets($agent, 1))->toBeTrue()
        ->and($authorizer->forAgent($agent, 1, $type, $agent->getKey()))->toBeTrue()
        ->and($authorizer->forAgent($agent, 1, $type, $agent->getKey() + 1))->toBeFalse();
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
