<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\TicketAssigned;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\TeamMember;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketParticipant;

it('assigns a ticket to a specific agent', function (): void {
    Event::fake([TicketAssigned::class]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $agent = makeUser(['name' => 'Agent']);

    Ticketing::for($ticket)->assignTo($agent);

    $ticket = $ticket->fresh();

    expect($ticket->assignee_type)->toBe($agent->getMorphClass())
        ->and((string) $ticket->assignee_id)->toBe((string) $agent->getKey())
        ->and(TicketParticipant::query()->where('ticket_id', $ticket->getKey())->where('role', ParticipantRole::Assignee->value)->count())->toBe(1);

    Event::assertDispatched(TicketAssigned::class);
});

it('distributes round-robin across team members', function (): void {
    $team = Team::factory()->create();
    $a = makeUser(['name' => 'A']);
    $b = makeUser(['name' => 'B']);
    TeamMember::factory()->member($a)->create(['team_id' => $team->getKey()]);
    TeamMember::factory()->member($b)->create(['team_id' => $team->getKey()]);

    $t1 = Ticketing::open(type: 'support', title: '1', requester: makeUser());
    $t2 = Ticketing::open(type: 'support', title: '2', requester: makeUser());

    Ticketing::for($t1)->assignToTeam($team, 'round-robin');
    Ticketing::for($t2)->assignToTeam($team, 'round-robin');

    $first = $t1->fresh()->assignee_id;
    $second = $t2->fresh()->assignee_id;

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first)->not->toBe($second); // rotated
});

it('assigns least-busy to the agent with the fewest open tickets', function (): void {
    $team = Team::factory()->create();
    $busy = makeUser(['name' => 'Busy']);
    $free = makeUser(['name' => 'Free']);
    TeamMember::factory()->member($busy)->create(['team_id' => $team->getKey()]);
    TeamMember::factory()->member($free)->create(['team_id' => $team->getKey()]);

    // Give "busy" an open ticket already.
    Ticket::factory()->assignedTo($busy)->create();

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Ticketing::for($ticket)->assignToTeam($team, 'least-busy');

    expect((string) $ticket->fresh()->assignee_id)->toBe((string) $free->getKey());
});

it('respects required skills in skill-based routing', function (): void {
    $team = Team::factory()->create();
    $phpDev = makeUser(['name' => 'PHP']);
    $jsDev = makeUser(['name' => 'JS']);
    TeamMember::factory()->member($phpDev)->skills(['php'])->create(['team_id' => $team->getKey()]);
    TeamMember::factory()->member($jsDev)->skills(['js'])->create(['team_id' => $team->getKey()]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser(), attributes: ['custom_fields' => ['required_skills' => ['php']]]);
    Ticketing::for($ticket)->assignToTeam($team, 'skill-based');

    expect((string) $ticket->fresh()->assignee_id)->toBe((string) $phpDev->getKey());
});

it('skips unavailable agents', function (): void {
    $team = Team::factory()->create();
    $away = makeUser(['name' => 'Away', 'available' => false]);
    $here = makeUser(['name' => 'Here', 'available' => true]);
    TeamMember::factory()->member($away)->create(['team_id' => $team->getKey()]);
    TeamMember::factory()->member($here)->create(['team_id' => $team->getKey()]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Ticketing::for($ticket)->assignToTeam($team, 'round-robin');

    expect((string) $ticket->fresh()->assignee_id)->toBe((string) $here->getKey());
});

it('leaves the ticket unassigned with the manual strategy', function (): void {
    $team = Team::factory()->create();
    TeamMember::factory()->member(makeUser())->create(['team_id' => $team->getKey()]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Ticketing::for($ticket)->assignToTeam($team, 'manual');

    $ticket = $ticket->fresh();
    expect($ticket->assignee_id)->toBeNull()
        ->and((string) $ticket->team_id)->toBe((string) $team->getKey()); // team set, no agent
});
