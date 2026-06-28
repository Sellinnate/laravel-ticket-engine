<?php

declare(strict_types=1);

use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\RoutingRule;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\TeamMember;

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

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    expect((string) $ticket->fresh()->team_id)->toBe((string) $teamA->getKey()); // first match wins
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
