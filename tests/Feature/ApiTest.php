<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Tenancy\TenantContext;

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

it('rejects an empty or unknown assignment', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $this->actingAs(makeUser());

    $this->postJson(API.'/tickets/'.$ticket->getKey().'/assignment', [])->assertStatus(422);
    $this->postJson(API.'/tickets/'.$ticket->getKey().'/assignment', ['team_id' => 999999])->assertStatus(422);
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
