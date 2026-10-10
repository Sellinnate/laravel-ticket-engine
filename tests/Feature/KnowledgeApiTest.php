<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\KbCategory;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Tests\Fixtures\TestRequester;

function kbArticle(string $title, KnowledgeVisibility $visibility = KnowledgeVisibility::Public, bool $publish = true): KbArticle
{
    $article = Ticketing::knowledge()->save(new ArticleData(title: $title, body: $title.' body text', visibility: $visibility));

    return $publish ? Ticketing::knowledge()->publish($article) : $article;
}

it('lets agents write, publish and archive articles', function (): void {
    $this->actingAs(makeUser());

    $created = $this->postJson(API.'/kb/articles', [
        'title' => 'Reset password',
        'body' => 'Open settings.',
        'keywords' => ['password'],
        'visibility' => 'customer',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.visibility', 'customer');

    $id = $created->json('data.id');

    $this->patchJson(API."/kb/articles/{$id}", ['body' => 'Open settings, then security.'])
        ->assertOk()
        ->assertJsonPath('data.version', 2);

    $this->postJson(API."/kb/articles/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
    $this->postJson(API."/kb/articles/{$id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');

    $this->postJson(API.'/kb/articles', ['title' => 'No body'])->assertStatus(422);
});

it('keeps writing to agents', function (): void {
    $article = kbArticle('Public');
    $this->actingAs(TestRequester::query()->create(['name' => 'Customer']));

    $this->postJson(API.'/kb/articles', ['title' => 'x', 'body' => 'y'])->assertForbidden();
    $this->patchJson(API."/kb/articles/{$article->getKey()}", ['body' => 'z'])->assertForbidden();
    $this->postJson(API."/kb/articles/{$article->getKey()}/publish")->assertForbidden();
});

it('lists, searches and shows what the caller may read', function (): void {
    kbArticle('Public guide');
    kbArticle('Customer guide', KnowledgeVisibility::Customer);
    $internal = kbArticle('Internal guide', KnowledgeVisibility::Internal);
    $draft = kbArticle('Draft guide', publish: false);

    $this->actingAs(TestRequester::query()->create(['name' => 'Customer']));

    $this->getJson(API.'/kb/articles')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(API.'/kb/articles?q=guide')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(API."/kb/articles/{$internal->getKey()}")->assertForbidden();
    $this->getJson(API."/kb/articles/{$draft->getKey()}")->assertForbidden();

    $this->actingAs(makeUser());
    $this->getJson(API.'/kb/articles?q=guide')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson(API."/kb/articles/{$draft->getKey()}")->assertOk()->assertJsonPath('data.status', 'draft');
});

it('hides other tenants\' articles and refuses malformed ids', function (): void {
    $foreign = app(TenantContext::class)->forTenant(9, fn () => kbArticle('Foreign'));
    $this->actingAs(makeUser(['tenant_id' => 1]));

    $this->getJson(API."/kb/articles/{$foreign->getKey()}")->assertNotFound();
    $this->getJson(API.'/kb/articles/not-a-number')->assertNotFound();
    $this->getJson(API.'/tickets/not-a-number')->assertNotFound();
});

it('records votes and links articles to tickets', function (): void {
    $article = kbArticle('Fix it');
    $ticket = Ticketing::open(type: 'support', title: 'Broken');
    $this->actingAs(makeUser());

    $this->postJson(API."/kb/articles/{$article->getKey()}/feedback", ['helpful' => true])->assertCreated();
    $this->postJson(API."/tickets/{$ticket->getKey()}/articles", ['article_id' => $article->getKey(), 'relation' => 'resolved_by'])
        ->assertCreated()
        ->assertJsonPath('data.relation', 'resolved_by');

    expect($article->refresh()->helpful_count)->toBe(1)
        ->and($ticket->articleLinks()->count())->toBe(1);

    $this->actingAs(TestRequester::query()->create(['name' => 'Customer']));
    $this->postJson(API."/tickets/{$ticket->getKey()}/articles", ['article_id' => $article->getKey()])->assertForbidden();
});

it('lets agents take over and anyone on the ticket ask for a human', function (): void {
    config()->set('ticketing.ai.escalation.handoff_message', null);
    $requester = TestRequester::query()->create(['name' => 'Customer']);
    $ticket = Ticketing::open(type: 'support', title: 'Help', requester: $requester);
    $ticket->forceFill(['ai_status' => AiStatus::Active])->save();

    $this->actingAs($requester);
    $this->postJson(API."/tickets/{$ticket->getKey()}/takeover")->assertForbidden();
    $this->postJson(API."/tickets/{$ticket->getKey()}/escalate")
        ->assertOk()
        ->assertJsonPath('data.ai.status', 'escalated')
        ->assertJsonPath('data.ai.escalation_reason', EscalationReason::HumanRequested->value);

    $agent = makeUser(['name' => 'Agent']);
    $this->actingAs($agent);
    $this->postJson(API."/tickets/{$ticket->getKey()}/takeover")
        ->assertOk()
        ->assertJsonPath('data.ai.status', 'suspended')
        ->assertJsonPath('data.assignee.id', (string) $agent->getKey());
});

it('exposes the AI sources on AI messages', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x');
    Ticketing::postMessage($ticket, null, 'AI said', meta: ['ai' => ['sources' => [['title' => 'Doc']], 'confidence' => 0.9]]);
    $this->actingAs(makeUser());

    $this->getJson(API."/tickets/{$ticket->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.messages.0.ai.sources.0.title', 'Doc')
        ->assertJsonPath('data.messages.0.ai.confidence', 0.9);
});

it('never shows another tenant\'s articles to an agent, even by direct Gate check', function (): void {
    $article = app(TenantContext::class)->forTenant(2, fn () => kbArticle('Tenant two runbook', KnowledgeVisibility::Internal));
    $foreignAgent = makeUser(['tenant_id' => 1]);
    $ownAgent = makeUser(['tenant_id' => 2]);

    expect(Gate::forUser($foreignAgent)->allows('view', $article))->toBeFalse()
        ->and(Gate::forUser($ownAgent)->allows('view', $article))->toBeTrue();
});

it('validates the category of an article', function (): void {
    $own = KbCategory::query()->create(['name' => 'Billing']);
    $foreign = app(TenantContext::class)->forTenant(7, fn () => KbCategory::query()->create(['name' => 'Other']));
    $this->actingAs(makeUser());

    foreach (['abc', '999999', (string) $foreign->getKey()] as $bad) {
        $this->postJson(API.'/kb/articles', ['title' => 'T', 'body' => 'B', 'category_id' => $bad])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    $this->postJson(API.'/kb/articles', ['title' => 'T', 'body' => 'B', 'category_id' => $own->getKey()])
        ->assertCreated()
        ->assertJsonPath('data.category_id', $own->getKey());

    expect(fn () => Ticketing::knowledge()->save(new ArticleData(title: 'X', body: 'Y', categoryId: 999999)))
        ->toThrow(InvalidArgumentException::class);
});
