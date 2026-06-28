<?php

declare(strict_types=1);

use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\RoutingRule;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\TeamMember;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Routing\RoutingEngine;
use Selli\Ticketing\Tenancy\TenantContext;

it('routes a ticket to a team via a matching rule on open', function (): void {
    $team = Team::factory()->create();
    $agent = makeUser();
    TeamMember::factory()->member($agent)->create(['team_id' => $team->getKey()]);

    RoutingRule::factory()->create([
        'name' => 'Support to team',
        'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'support']],
        'team_id' => $team->getKey(),
        'strategy' => 'round-robin',
        'position' => 0,
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect((string) $ticket->fresh()->team_id)->toBe((string) $team->getKey())
        ->and((string) $ticket->fresh()->assignee_id)->toBe((string) $agent->getKey());
});

it('does not route when no rule matches', function (): void {
    $team = Team::factory()->create();
    RoutingRule::factory()->create([
        'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'incident']],
        'team_id' => $team->getKey(),
        'strategy' => 'manual',
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect($ticket->fresh()->team_id)->toBeNull();
});

it('applies rules in position order and stops at the first match', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    RoutingRule::factory()->create([
        'conditions' => [['field' => 'priority', 'operator' => '>=', 'value' => 20]],
        'team_id' => $teamA->getKey(),
        'strategy' => 'manual',
        'position' => 0,
    ]);
    RoutingRule::factory()->create([
        'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'support']],
        'team_id' => $teamB->getKey(),
        'strategy' => 'manual',
        'position' => 1,
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser(), priority: Priority::Normal);

    expect((string) $ticket->fresh()->team_id)->toBe((string) $teamA->getKey()); // first match wins
});

it('falls through to a backup rule when the first match has no valid target', function (): void {
    $context = app(TenantContext::class);
    $otherTeam = $context->forTenant(99, fn () => Team::factory()->create(['tenant_id' => 99]));

    // Position 0: shared rule pointing at a cross-tenant team (invalid target).
    RoutingRule::factory()->create([
        'tenant_id' => null,
        'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'support']],
        'team_id' => $otherTeam->getKey(),
        'strategy' => 'manual',
        'position' => 0,
    ]);

    [$ticket, $teamOk] = $context->forTenant(5, function () {
        $teamOk = Team::factory()->create(['tenant_id' => 5]);
        RoutingRule::factory()->create([
            'tenant_id' => 5,
            'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'support']],
            'team_id' => $teamOk->getKey(),
            'strategy' => 'manual',
            'position' => 1,
        ]);

        $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5]));

        return [Ticket::query()->withoutTenancy()->find($ticket->getKey()), $teamOk];
    });

    expect((string) $ticket->team_id)->toBe((string) $teamOk->getKey());
});

it('fails closed on an unknown routing field at the engine level', function (): void {
    RoutingRule::factory()->create([
        'conditions' => [['field' => 'bogus', 'operator' => '=', 'value' => 'x']],
        'team_id' => Team::factory()->create()->getKey(),
        'strategy' => 'manual',
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    // The engine throws on the bad field…
    expect(fn () => app(RoutingEngine::class)->route($ticket))
        ->toThrow(InvalidConfigurationException::class);
});

it('does not let a routing error break ticket creation', function (): void {
    // A bad rule must not fail (or half-complete) the open — the side-effect
    // subscriber reports the error and the ticket is simply left unrouted.
    RoutingRule::factory()->create([
        'conditions' => [['field' => 'bogus', 'operator' => '=', 'value' => 'x']],
        'team_id' => Team::factory()->create()->getKey(),
        'strategy' => 'manual',
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect($ticket->exists)->toBeTrue()
        ->and($ticket->fresh()->team_id)->toBeNull();
});

it('does not route to a deactivated team', function (): void {
    $team = Team::factory()->create(['is_active' => false]);
    RoutingRule::factory()->create([
        'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'support']],
        'team_id' => $team->getKey(),
        'strategy' => 'manual',
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect($ticket->fresh()->team_id)->toBeNull();
});

it('routes to an explicit assignee declared on a rule', function (): void {
    $agent = makeUser();
    RoutingRule::factory()->create([
        'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'support']],
        'assignee_type' => $agent->getMorphClass(),
        'assignee_id' => $agent->getKey(),
        'strategy' => 'manual',
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect((string) $ticket->fresh()->assignee_id)->toBe((string) $agent->getKey());
});

it('supports a range of condition operators', function (): void {
    $team = Team::factory()->create();
    RoutingRule::factory()->create([
        'conditions' => [
            ['field' => 'priority', 'operator' => '>', 'value' => 10],
            ['field' => 'category', 'operator' => '!=', 'value' => 'spam'],
            ['field' => 'custom_fields.tags', 'operator' => 'contains', 'value' => 'vip'],
            ['field' => 'status', 'operator' => 'not_in', 'value' => ['closed']],
        ],
        'team_id' => $team->getKey(),
        'strategy' => 'manual',
    ]);

    $ticket = Ticketing::open(
        type: 'support',
        title: 'x',
        requester: makeUser(),
        category: 'support',
        priority: Priority::Normal,
        attributes: ['custom_fields' => ['tags' => ['vip', 'eu']]],
    );

    expect((string) $ticket->fresh()->team_id)->toBe((string) $team->getKey());
});

it('ignores a matching rule with no team or assignee', function (): void {
    RoutingRule::factory()->create([
        'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'support']],
        'team_id' => null,
        'strategy' => 'manual',
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect($ticket->fresh()->team_id)->toBeNull()
        ->and($ticket->fresh()->assignee_id)->toBeNull();
});

it('never routes to another tenant team', function (): void {
    $context = app(TenantContext::class);
    $otherTeam = $context->forTenant(99, fn () => Team::factory()->create(['tenant_id' => 99]));

    // A shared rule pointing at tenant 99's team.
    RoutingRule::factory()->create([
        'tenant_id' => null,
        'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'support']],
        'team_id' => $otherTeam->getKey(),
        'strategy' => 'manual',
    ]);

    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5])));

    expect(Ticket::query()->withoutTenancy()->find($ticket->getKey())->team_id)->toBeNull();
});

it('never routes to an agent from another tenant', function (): void {
    $context = app(TenantContext::class);
    $otherAgent = $context->forTenant(99, fn () => makeUser(['name' => 'Other', 'tenant_id' => 99]));

    RoutingRule::factory()->create([
        'tenant_id' => null,
        'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'support']],
        'assignee_type' => $otherAgent->getMorphClass(),
        'assignee_id' => $otherAgent->getKey(),
        'strategy' => 'manual',
    ]);

    $ticket = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 5])));

    expect(Ticket::query()->withoutTenancy()->find($ticket->getKey())->assignee_id)->toBeNull();
});

it('prefers a tenant-owned rule over a shared rule at the same position', function (): void {
    $context = app(TenantContext::class);

    $teamShared = Team::factory()->create(['tenant_id' => null]);
    RoutingRule::factory()->create([
        'tenant_id' => null,
        'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'support']],
        'team_id' => $teamShared->getKey(),
        'strategy' => 'manual',
        'position' => 0,
    ]);

    [$ticket, $teamOwned] = $context->forTenant(7, function () {
        $teamOwned = Team::factory()->create(['tenant_id' => 7]);
        RoutingRule::factory()->create([
            'tenant_id' => 7,
            'conditions' => [['field' => 'type', 'operator' => '=', 'value' => 'support']],
            'team_id' => $teamOwned->getKey(),
            'strategy' => 'manual',
            'position' => 0,
        ]);

        $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser(['tenant_id' => 7]));

        return [Ticket::query()->withoutTenancy()->find($ticket->getKey()), $teamOwned];
    });

    expect((string) $ticket->team_id)->toBe((string) $teamOwned->getKey());
});

it('exposes the routing rule team relation', function (): void {
    $team = Team::factory()->create();
    $rule = RoutingRule::factory()->create(['team_id' => $team->getKey()]);

    expect($rule->team->is($team))->toBeTrue();
});

it('matches conditions across operators', function (): void {
    $team = Team::factory()->create();
    RoutingRule::factory()->create([
        'conditions' => [
            ['field' => 'priority', 'operator' => 'in', 'value' => [20, 30]],
            ['field' => 'custom_fields.region', 'operator' => '=', 'value' => 'EU'],
        ],
        'team_id' => $team->getKey(),
        'strategy' => 'manual',
    ]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser(), attributes: ['custom_fields' => ['region' => 'EU']]);

    expect((string) $ticket->fresh()->team_id)->toBe((string) $team->getKey());
});
