<?php

declare(strict_types=1);

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Mcp\TicketingServer;
use Selli\Ticketing\Mcp\Tools\AddInternalNoteTool;
use Selli\Ticketing\Mcp\Tools\AssignTicketTool;
use Selli\Ticketing\Mcp\Tools\GetArticleTool;
use Selli\Ticketing\Mcp\Tools\GetTicketTool;
use Selli\Ticketing\Mcp\Tools\ListTicketsTool;
use Selli\Ticketing\Mcp\Tools\ReplyToTicketTool;
use Selli\Ticketing\Mcp\Tools\SaveArticleTool;
use Selli\Ticketing\Mcp\Tools\SearchKnowledgeTool;
use Selli\Ticketing\Mcp\Tools\TakeOverTicketTool;
use Selli\Ticketing\Mcp\Tools\TicketMetricsTool;
use Selli\Ticketing\Mcp\Tools\TransitionTicketTool;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Tests\Fixtures\TestRequester;

beforeEach(function (): void {
    if (! class_exists(Server::class)) {
        $this->markTestSkipped('laravel/mcp is not installed (it needs Laravel 12.41+).');
    }

    // A host gets it through package discovery.
    app()->register(McpServiceProvider::class);
});

it('exposes every help-desk tool', function (): void {
    TicketingServer::tools()->assertRegistered(TicketingServer::TOOLS);
});

it('refuses to work without an authenticated user', function (): void {
    TicketingServer::tool(ListTicketsTool::class)->assertHasErrors(['An authenticated user is required.']);
});

it('lists the tenant for agents and only their own tickets for customers', function (): void {
    $customer = TestRequester::query()->create(['name' => 'Customer']);
    $mine = Ticketing::open(type: 'support', title: 'Mine', requester: $customer);
    Ticketing::open(type: 'support', title: 'Someone else', channel: 'email');

    TicketingServer::actingAs(makeUser())->tool(ListTicketsTool::class)
        ->assertOk()
        ->assertSee(['"total":2', $mine->reference]);

    TicketingServer::actingAs(makeUser())->tool(ListTicketsTool::class, ['channel' => 'email'])->assertSee('"total":1');

    TicketingServer::actingAs($customer)->tool(ListTicketsTool::class)
        ->assertSee(['"total":1', $mine->reference])
        ->assertDontSee('Someone else');
});

it('reads a ticket with internal notes for agents only, and hides what the user may not see', function (): void {
    $customer = TestRequester::query()->create(['name' => 'Customer']);
    $ticket = Ticketing::open(type: 'support', title: 'Printer', requester: $customer);
    Ticketing::postMessage($ticket, $customer, 'It is broken.');
    Ticketing::postMessage($ticket, makeUser(), 'Agents only: known issue.', MessageVisibility::Internal);
    $other = Ticketing::open(type: 'support', title: 'Not yours');

    TicketingServer::actingAs(makeUser())->tool(GetTicketTool::class, ['ticket' => $ticket->reference])
        ->assertOk()
        ->assertSee(['It is broken.', 'Agents only: known issue.']);

    TicketingServer::actingAs($customer)->tool(GetTicketTool::class, ['ticket' => (string) $ticket->getKey()])
        ->assertSee('It is broken.')
        ->assertDontSee('Agents only');

    TicketingServer::actingAs($customer)->tool(GetTicketTool::class, ['ticket' => $other->reference])
        ->assertHasErrors(["Ticket [{$other->reference}] not found."]);

    // Values that cannot be a key of the configured type are simply unknown.
    foreach (['01ARZ3NDEKTSV4RRFFQ69G5FAV', '99999999999999999999999'] as $value) {
        TicketingServer::actingAs(makeUser())->tool(GetTicketTool::class, ['ticket' => $value])->assertHasErrors(["Ticket [{$value}] not found."]);
    }

    // An agent of another tenant, working in that tenant, cannot reach it.
    $foreign = app(TenantContext::class)->forTenant(9, fn () => Ticketing::open(type: 'support', title: 'Other tenant'));
    app(TenantContext::class)->forTenant(3, fn () => TicketingServer::actingAs(makeUser(['tenant_id' => 3]))
        ->tool(GetTicketTool::class, ['ticket' => $foreign->reference])
        ->assertHasErrors(["Ticket [{$foreign->reference}] not found."]));
});

it('replies publicly, and keeps internal notes to agents', function (): void {
    $customer = TestRequester::query()->create(['name' => 'Customer']);
    $ticket = Ticketing::open(type: 'support', title: 'Printer', requester: $customer);

    TicketingServer::actingAs(makeUser())->tool(ReplyToTicketTool::class, ['ticket' => $ticket->reference, 'body' => 'Try again now.'])->assertOk();
    TicketingServer::actingAs(makeUser())->tool(AddInternalNoteTool::class, ['ticket' => $ticket->reference, 'body' => 'Driver bug.'])->assertOk();
    TicketingServer::actingAs($customer)->tool(ReplyToTicketTool::class, ['ticket' => $ticket->reference, 'body' => 'Works, thanks.'])->assertOk();
    TicketingServer::actingAs($customer)->tool(AddInternalNoteTool::class, ['ticket' => $ticket->reference, 'body' => 'Sneaky'])
        ->assertHasErrors(['You are not allowed to do that on this ticket.']);
    TicketingServer::actingAs(makeUser())->tool(ReplyToTicketTool::class, ['ticket' => $ticket->reference])->assertHasErrors();

    expect($ticket->messages()->where('visibility', MessageVisibility::Public->value)->pluck('body')->all())->toBe(['Try again now.', 'Works, thanks.'])
        ->and($ticket->messages()->where('visibility', MessageVisibility::Internal->value)->pluck('body')->all())->toBe(['Driver bug.']);
});

it('assigns to the caller or a team', function (): void {
    $agent = makeUser(['name' => 'Ada']);
    $team = Team::factory()->create(['key' => 'desk']);
    $ticket = Ticketing::open(type: 'support', title: 'Printer');

    TicketingServer::actingAs($agent)->tool(AssignTicketTool::class, ['ticket' => $ticket->reference, 'assignee' => 'me', 'team' => 'desk'])->assertOk();

    expect($ticket->refresh()->assignee_id)->toBe((string) $agent->getKey())
        ->and($ticket->team_id)->toBe($team->getKey());

    TicketingServer::actingAs($agent)->tool(AssignTicketTool::class, ['ticket' => $ticket->reference, 'team' => 'nope'])->assertHasErrors(['Team [nope] not found.']);
    TicketingServer::actingAs($agent)->tool(AssignTicketTool::class, ['ticket' => $ticket->reference])->assertHasErrors();
});

it('needs an explicit confirmation to move a ticket through its workflow', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Printer');

    TicketingServer::actingAs(makeUser())->tool(TransitionTicketTool::class, ['ticket' => $ticket->reference, 'transition' => 'resolve'])
        ->assertHasErrors(["Set \"confirm\": true to apply [resolve] to {$ticket->reference}."]);
    expect($ticket->refresh()->status)->toBe('open');

    TicketingServer::actingAs(makeUser())->tool(TransitionTicketTool::class, ['ticket' => $ticket->reference, 'transition' => 'resolve', 'confirm' => true])
        ->assertOk()
        ->assertSee('"status":"resolved"');

    TicketingServer::actingAs(makeUser())->tool(TransitionTicketTool::class, ['ticket' => $ticket->reference, 'transition' => 'teleport', 'confirm' => true])
        ->assertHasErrors();

    $customer = TestRequester::query()->create(['name' => 'Customer']);
    $own = Ticketing::open(type: 'support', title: 'Mine', requester: $customer);
    TicketingServer::actingAs($customer)->tool(TransitionTicketTool::class, ['ticket' => $own->reference, 'transition' => 'resolve', 'confirm' => true])
        ->assertHasErrors(['You are not allowed to do that on this ticket.']);
});

it('takes a ticket over from the AI', function (): void {
    $agent = makeUser();
    $ticket = Ticketing::open(type: 'support', title: 'Printer');
    $ticket->forceFill(['ai_status' => AiStatus::Active])->save();

    TicketingServer::actingAs($agent)->tool(TakeOverTicketTool::class, ['ticket' => $ticket->reference])->assertOk();

    expect($ticket->refresh()->ai_status)->toBe(AiStatus::Suspended)
        ->and($ticket->assignee_id)->toBe((string) $agent->getKey());
});

it('reads the KPIs for agents only', function (): void {
    Ticketing::open(type: 'support', title: 'Printer', channel: 'email');

    TicketingServer::actingAs(makeUser())->tool(TicketMetricsTool::class, ['channel' => 'email'])
        ->assertOk()
        ->assertSee(['"created":1', '"backlog"']);

    // A bare end date covers its whole day.
    TicketingServer::actingAs(makeUser())->tool(TicketMetricsTool::class, ['from' => now()->toDateString(), 'to' => now()->toDateString()])
        ->assertSee('"created":1');

    TicketingServer::actingAs(makeUser())->tool(TicketMetricsTool::class, ['from' => '2026-03-10', 'to' => '2026-03-01'])->assertHasErrors();
    TicketingServer::actingAs(makeUser())->tool(TicketMetricsTool::class, ['from' => 'last week'])->assertHasErrors();
    TicketingServer::actingAs(TestRequester::query()->create(['name' => 'Customer']))->tool(TicketMetricsTool::class)
        ->assertHasErrors(['You are not allowed to do that.']);
});

it('searches and reads the articles the user may read, and drafts new ones', function (): void {
    $public = Ticketing::knowledge()->publish(Ticketing::knowledge()->save(new ArticleData(title: 'Reset your password', body: 'Open Settings, then Security.', visibility: KnowledgeVisibility::Public)));
    Ticketing::knowledge()->publish(Ticketing::knowledge()->save(new ArticleData(title: 'Password runbook', body: 'Internal reset procedure.', visibility: KnowledgeVisibility::Internal)));
    $customer = TestRequester::query()->create(['name' => 'Customer']);

    TicketingServer::actingAs($customer)->tool(SearchKnowledgeTool::class, ['query' => 'password'])
        ->assertSee($public->slug)
        ->assertDontSee('runbook');

    TicketingServer::actingAs($customer)->tool(GetArticleTool::class, ['slug' => $public->slug])->assertSee('Open Settings, then Security.');
    TicketingServer::actingAs($customer)->tool(GetArticleTool::class, ['slug' => 'password-runbook'])->assertHasErrors(['Article [password-runbook] not found.']);

    TicketingServer::actingAs(makeUser())->tool(SaveArticleTool::class, ['title' => 'Printer offline', 'body' => 'Restart the spooler.', 'type' => 'faq'])
        ->assertOk()
        ->assertSee('"status":"draft"');

    $draft = KbArticle::query()->where('title', 'Printer offline')->sole();
    expect($draft->status->value)->toBe('draft');

    // Drafts can be reworked; what customers read cannot be changed from here.
    TicketingServer::actingAs(makeUser())->tool(SaveArticleTool::class, ['id' => (string) $draft->getKey(), 'body' => 'Restart the print spooler service.'])->assertOk();
    expect($draft->refresh()->body)->toBe('Restart the print spooler service.');

    TicketingServer::actingAs(makeUser())->tool(SaveArticleTool::class, ['id' => (string) $public->getKey(), 'body' => 'Defaced'])
        ->assertHasErrors(["Article [{$public->getKey()}] is published: only drafts can be edited here. Create a new draft instead."]);
    expect($public->refresh()->body)->toBe('Open Settings, then Security.');

    TicketingServer::actingAs(makeUser())->tool(SaveArticleTool::class, ['id' => 'printer-offline', 'body' => 'x'])
        ->assertHasErrors(['Article [printer-offline] not found.']);

    TicketingServer::actingAs($customer)->tool(SaveArticleTool::class, ['title' => 'Spam', 'body' => 'Buy now'])
        ->assertHasErrors(['You are not allowed to do that.']);
});
