<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Routing\AssignmentManager;
use Selli\Ticketing\Routing\Strategies\ManualStrategy;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Tests\Fixtures\TestRequester;

const API = '/ticketing/api/v1';

it('creates a ticket', function (): void {
    $this->actingAs(makeUser());

    $response = $this->postJson(API.'/tickets', [
        'type' => 'support',
        'title' => 'My printer is broken',
        'priority' => Priority::High->value,
        'category' => 'hardware',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'My printer is broken')
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.priority', Priority::High->value);
});

it('validates ticket creation', function (): void {
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets', ['title' => 'no type'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('lists and shows tickets scoped to the tenant', function (): void {
    $context = app(TenantContext::class);
    $mine = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'Mine', requester: makeUser(['tenant_id' => 5])));
    $context->forTenant(9, fn () => Ticketing::open(type: 'support', title: 'Theirs', requester: makeUser(['tenant_id' => 9])));

    $this->actingAs(makeUser(['tenant_id' => 5]));

    $this->getJson(API.'/tickets')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Mine');

    $this->getJson(API.'/tickets/'.$mine->getKey())->assertOk()->assertJsonPath('data.reference', $mine->reference);
});

it('ignores array-shaped list filters instead of 500ing', function (): void {
    Ticketing::open(type: 'support', title: 'Mine', requester: makeUser());
    $this->actingAs(makeUser());

    // ?status[]=open&priority[]=10&per_page[]=5 must not reach where() as arrays.
    $this->getJson(API.'/tickets?status[]=open&priority[]=10&per_page[]=5')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('hides internal notes on show', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    Ticketing::for($ticket)->postMessage(makeUser(), 'public reply');
    Ticketing::for($ticket)->postMessage(makeUser(), 'internal note', MessageVisibility::Internal);
    $this->actingAs(makeUser());

    $response = $this->getJson(API.'/tickets/'.$ticket->getKey());

    $response->assertOk()->assertJsonCount(1, 'data.messages')
        ->assertJsonPath('data.messages.0.body', 'public reply');
});

it('rejects an out-of-enum priority and out-of-range rating', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets', ['type' => 'support', 'title' => 'x', 'priority' => 999])
        ->assertStatus(422)->assertJsonValidationErrors('priority');

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/csat', ['rating' => 99])
        ->assertStatus(422)->assertJsonValidationErrors('rating');
});

it('rejects a CSAT token that names another ticket', function (): void {
    $a = Ticketing::open(type: 'support', title: 'A', requester: makeUser());
    $b = Ticketing::open(type: 'support', title: 'B', requester: makeUser());
    $tokenForB = Ticketing::csatToken($b);
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$a->getKey().'/csat', ['rating' => 5, 'token' => $tokenForB])
        ->assertStatus(422)->assertJsonValidationErrors('token');
});

it('404s a ticket from another tenant', function (): void {
    $context = app(TenantContext::class);
    $theirs = $context->forTenant(9, fn () => Ticketing::open(type: 'support', title: 'Theirs', requester: makeUser(['tenant_id' => 9])));

    $this->actingAs(makeUser(['tenant_id' => 5]));

    $this->getJson(API.'/tickets/'.$theirs->getKey())->assertNotFound();
});

it('posts a message', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/messages', ['body' => 'Working on it', 'visibility' => 'public'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Working on it');

    expect($ticket->fresh()->messages()->count())->toBe(1);
});

it('lets an agent post an internal note but not a requester', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    // An agent (CanActOnTickets) may post internal.
    $this->actingAs(makeUser());
    $this->postJson(API.'/tickets/'.$ticket->getKey().'/messages', ['body' => 'note', 'visibility' => 'internal'])
        ->assertCreated();

    // A requester-only user may not.
    $requester = TestRequester::query()->create(['name' => 'Req']);
    $this->actingAs($requester);
    $this->postJson(API.'/tickets/'.$ticket->getKey().'/messages', ['body' => 'sneaky', 'visibility' => 'internal'])
        ->assertStatus(422)->assertJsonValidationErrors('visibility');
});

it('returns 422 (not 500) for an unknown ticket type', function (): void {
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets', ['type' => 'no-such-type', 'title' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('type');
});

it('returns 422 (not 500) for a disallowed transition', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/transitions', ['transition' => 'reopen'])
        ->assertStatus(422)->assertJsonValidationErrors('transition');
});

it('transitions a ticket', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/transitions', ['transition' => 'resolve'])
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved');
});

it('assigns a ticket to a team', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $team = Team::factory()->create();
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/assignment', ['team_id' => $team->getKey()])
        ->assertOk()
        ->assertJsonPath('data.team_id', (int) $team->getKey());
});

it('self-assigns via assign_to_me', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $agent = makeUser();
    $this->actingAs($agent);

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/assignment', ['assign_to_me' => true])
        ->assertOk()
        ->assertJsonPath('data.assignee.id', fn ($id): bool => (string) $id === (string) $agent->getKey());
});

it('accepts a host-registered custom assignment strategy', function (): void {
    app(AssignmentManager::class)
        ->extend('priority-weighted', fn ($container) => $container->make(ManualStrategy::class));

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $team = Team::factory()->create();
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/assignment', ['team_id' => $team->getKey(), 'strategy' => 'priority-weighted'])
        ->assertOk()
        ->assertJsonPath('data.team_id', (int) $team->getKey());
});

it('rejects an unknown assignment strategy', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $team = Team::factory()->create();
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/assignment', ['team_id' => $team->getKey(), 'strategy' => 'no-such-strategy'])
        ->assertStatus(422)->assertJsonValidationErrors('strategy');
});

it('rejects an empty or unknown assignment', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/assignment', [])->assertStatus(422);
    $this->postJson(API.'/tickets/'.$ticket->getKey().'/assignment', ['team_id' => 999999])->assertStatus(422);
});

it('forbids a requester from assigning a ticket (policy)', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $requester = TestRequester::query()->create(['name' => 'Req']);
    $this->actingAs($requester);

    // The assign policy is agent-only, so a requester is denied before the
    // controller's assign_to_me check is even reached.
    $this->postJson(API.'/tickets/'.$ticket->getKey().'/assignment', ['assign_to_me' => true])
        ->assertForbidden();
});

it('forbids a stranger from viewing or acting on a ticket they are not on', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    // A requester-only account that is NOT this ticket's requester/participant.
    $stranger = TestRequester::query()->create(['name' => 'Nosy']);
    $this->actingAs($stranger);

    $this->getJson(API.'/tickets/'.$ticket->getKey())->assertForbidden();
    $this->postJson(API.'/tickets/'.$ticket->getKey().'/transitions', ['transition' => 'resolve'])->assertForbidden();
    $this->postJson(API.'/tickets/'.$ticket->getKey().'/messages', ['body' => 'hi'])->assertForbidden();
});

it('lets a ticket requester view and reply to their own ticket', function (): void {
    $requester = TestRequester::query()->create(['name' => 'Owner']);
    $ticket = Ticketing::open(type: 'support', title: 'Mine', requester: $requester);
    $this->actingAs($requester);

    $this->getJson(API.'/tickets/'.$ticket->getKey())->assertOk()->assertJsonPath('data.title', 'Mine');
    $this->postJson(API.'/tickets/'.$ticket->getKey().'/messages', ['body' => 'any update?'])
        ->assertCreated();
});

it('returns 422 (not 500) when CSAT is rejected by the domain', function (): void {
    config()->set('ticketing.csat.enabled', false);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/csat', ['rating' => 5])
        ->assertStatus(422)->assertJsonValidationErrors('rating');
});

it('submits CSAT by token', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $token = Ticketing::csatToken($ticket);
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/csat', ['rating' => 4, 'token' => $token])
        ->assertCreated()
        ->assertJsonPath('data.rating', 4);
});

it('uploads an attachment', function (): void {
    Storage::fake('local');
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $this->actingAs(makeUser());

    $this->post(API.'/tickets/'.$ticket->getKey().'/attachments', [
        'file' => UploadedFile::fake()->create('report.pdf', 10),
    ])->assertCreated()->assertJsonPath('name', 'report.pdf');
});

it('submits CSAT', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/csat', ['rating' => 5, 'comment' => 'Great'])
        ->assertCreated()
        ->assertJsonPath('data.rating', 5);
});
