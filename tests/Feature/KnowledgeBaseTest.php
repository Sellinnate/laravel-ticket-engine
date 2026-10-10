<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Selli\Ticketing\Actions\Knowledge\SaveArticle;
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\ArticleLinkRelation;
use Selli\Ticketing\Enums\ArticleStatus;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Events\ArticleArchived;
use Selli\Ticketing\Events\ArticleFeedbackRecorded;
use Selli\Ticketing\Events\ArticleLinkedToTicket;
use Selli\Ticketing\Events\ArticlePublished;
use Selli\Ticketing\Events\ArticleSaved;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Exceptions\ImmutableAuditException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Knowledge\KnowledgeResult;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\KbArticleVersion;
use Selli\Ticketing\Models\KbCategory;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Tests\Fixtures\TestRequester;

function article(string $title, string $body, array $overrides = [], bool $publish = true): KbArticle
{
    $kb = Ticketing::knowledge();
    $article = $kb->save(new ArticleData(...array_merge(['title' => $title, 'body' => $body], $overrides)));

    return $publish ? $kb->publish($article) : $article;
}

/**
 * @param  list<KnowledgeResult>  $results
 * @return list<string>
 */
function resultTitles(array $results): array
{
    return array_map(fn (KnowledgeResult $result): string => $result->article->title, $results);
}

it('creates a draft at version 1 with a snapshot', function (): void {
    Event::fake([ArticleSaved::class]);
    $author = makeUser();

    $article = Ticketing::knowledge()->save(new ArticleData(
        title: 'Reset your password',
        body: 'Open **Settings** and choose *Reset*.',
        keywords: ['Password', 'login', 'password'],
    ), author: $author);

    expect($article->status)->toBe(ArticleStatus::Draft)
        ->and($article->version)->toBe(1)
        ->and($article->slug)->toBe('reset-your-password')
        ->and($article->locale)->toBe('en')
        ->and($article->keywords)->toBe('password, login')
        ->and($article->translation_group)->not->toBeNull()
        ->and((string) $article->author_id)->toBe((string) $author->getKey())
        ->and($article->versions()->count())->toBe(1);

    Event::assertDispatched(ArticleSaved::class);
});

it('requires a title and body for a new article', function (): void {
    expect(fn () => Ticketing::knowledge()->save(new ArticleData(title: 'No body')))
        ->toThrow(InvalidArgumentException::class);
});

it('versions content changes only', function (): void {
    $kb = Ticketing::knowledge();
    $article = article('Shipping times', 'Orders ship in 2 days.', publish: false);

    $updated = $kb->save(new ArticleData(body: 'Orders ship in 1 day.'), $article);
    $metaOnly = $kb->save(new ArticleData(visibility: KnowledgeVisibility::Customer), $updated);

    expect($updated->version)->toBe(2)
        ->and($metaOnly->version)->toBe(2)
        ->and($metaOnly->visibility)->toBe(KnowledgeVisibility::Customer)
        ->and($article->versions()->pluck('body')->all())->toBe(['Orders ship in 2 days.', 'Orders ship in 1 day.']);
});

it('keeps versions immutable', function (): void {
    article('Immutable', 'Body', publish: false);
    $version = KbArticleVersion::query()->sole();

    expect(fn () => $version->forceFill(['body' => 'tampered'])->save())->toThrow(ImmutableAuditException::class)
        ->and(fn () => KbArticleVersion::query()->update(['body' => 'x']))->toThrow(ImmutableAuditException::class)
        ->and(fn () => $version->delete())->toThrow(ImmutableAuditException::class);
});

it('keeps slugs unique per locale with a numeric suffix', function (): void {
    $first = article('Billing FAQ', 'One', publish: false);
    $second = article('Billing FAQ', 'Two', publish: false);
    $italian = article('Billing FAQ', 'Tre', ['locale' => 'it'], publish: false);
    $renamed = Ticketing::knowledge()->save(new ArticleData(slug: 'Billing FAQ'), $second);

    expect($first->slug)->toBe('billing-faq')
        ->and($second->slug)->toBe('billing-faq-2')
        ->and($italian->slug)->toBe('billing-faq')
        ->and($renamed->slug)->toBe('billing-faq-2');
});

it('groups translations and finds them by locale', function (): void {
    $english = article('Returns', 'Return within 30 days.');
    $italian = article('Resi', 'Reso entro 30 giorni.', ['locale' => 'it', 'translationOf' => $english]);

    $audience = KnowledgeAudience::public();

    expect($italian->translation_group)->toBe($english->translation_group)
        ->and(Ticketing::knowledge()->translation($english, 'it', $audience)?->getKey())->toBe($italian->getKey())
        ->and(Ticketing::knowledge()->translation($english, 'en', $audience)?->getKey())->toBe($english->getKey())
        ->and(Ticketing::knowledge()->translation($english, 'de', $audience))->toBeNull()
        ->and($english->translations()->pluck('locale')->all())->toBe(['it']);
});

it('refuses a translation of another tenant\'s article', function (): void {
    $context = app(TenantContext::class);
    $foreign = $context->forTenant(1, fn () => article('Foreign', 'Body', publish: false));

    $context->forTenant(2, function () use ($foreign): void {
        expect(fn () => Ticketing::knowledge()->save(new ArticleData(title: 'Copy', body: 'B', translationOf: $foreign)))
            ->toThrow(CrossTenantException::class);
    });
});

it('publishes and archives once, emitting events', function (): void {
    Event::fake([ArticlePublished::class, ArticleArchived::class]);
    $article = article('Lifecycle', 'Body', publish: false);
    $kb = Ticketing::knowledge();

    $published = $kb->publish($article);
    $kb->publish($published);
    $archived = $kb->archive($published);
    $kb->archive($archived);

    expect($published->status)->toBe(ArticleStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and($archived->status)->toBe(ArticleStatus::Archived)
        ->and($archived->archived_at)->not->toBeNull();

    Event::assertDispatchedTimes(ArticlePublished::class, 1);
    Event::assertDispatchedTimes(ArticleArchived::class, 1);
});

it('shows each audience only what it may read', function (): void {
    $client = makeOrder();
    $other = makeOrder();

    article('Public guide', 'Everyone can read this guide.');
    article('Customer guide', 'Signed-in customers read this guide.', ['visibility' => KnowledgeVisibility::Customer]);
    article('Internal runbook guide', 'Agents only guide.', ['visibility' => KnowledgeVisibility::Internal]);
    article('Client guide', 'Only for one client guide.', ['visibility' => KnowledgeVisibility::Customer, 'audience' => $client]);
    article('Client public guide', 'Public on one client widget guide.', ['audience' => $client]);
    article('Draft guide', 'Not published guide.', publish: false);

    $titles = fn (KnowledgeAudience $audience): array => Ticketing::knowledge()->articles($audience)->pluck('title')->sort()->values()->all();

    expect($titles(KnowledgeAudience::public()))->toBe(['Public guide'])
        ->and($titles(KnowledgeAudience::public($client)))->toBe(['Client public guide', 'Public guide'])
        ->and($titles(KnowledgeAudience::customer()))->toBe(['Customer guide', 'Public guide'])
        ->and($titles(KnowledgeAudience::customer($client)))->toBe(['Client guide', 'Client public guide', 'Customer guide', 'Public guide'])
        ->and($titles(KnowledgeAudience::customer($other)))->toBe(['Customer guide', 'Public guide'])
        ->and($titles(KnowledgeAudience::agent()))->toHaveCount(5);

    $search = fn (KnowledgeAudience $audience): array => resultTitles(Ticketing::knowledge()->search('guide', $audience, limit: 20));

    expect($search(KnowledgeAudience::public()))->toBe(['Public guide'])
        ->and($search(KnowledgeAudience::agent()))->toContain('Internal runbook guide')
        ->and($search(KnowledgeAudience::customer()))->not->toContain('Internal runbook guide');
});

it('derives the audience from the actor', function (): void {
    $agent = makeUser();
    $requester = TestRequester::query()->create(['name' => 'R']);

    expect(KnowledgeAudience::for($agent)->agent)->toBeTrue()
        ->and(KnowledgeAudience::for($requester)->identified)->toBeTrue()
        ->and(KnowledgeAudience::for($requester)->agent)->toBeFalse()
        ->and(KnowledgeAudience::for(null)->visibilities())->toBe([KnowledgeVisibility::Public])
        ->and(KnowledgeAudience::scoped('client', 7, true)->scopeKey())->toBe('client:7')
        ->and(KnowledgeAudience::scoped(null, null)->scopeKey())->toBe('*');
});

it('ranks title matches above body matches and builds an excerpt', function (): void {
    article('Invoice download', 'Open the billing page to get your PDF.', ['summary' => 'Get a copy of an invoice.']);
    article('Account settings', 'You can also download an invoice from the account page, it is quick.');
    article('Unrelated', 'Nothing to see here.');

    $results = Ticketing::knowledge()->search('download invoice', KnowledgeAudience::public());

    expect(resultTitles($results))->toBe(['Invoice download', 'Account settings'])
        ->and($results[0]->score)->toBeGreaterThan($results[1]->score)
        ->and($results[0]->score)->toBeLessThanOrEqual(1.0)
        ->and($results[1]->excerpt)->toContain('invoice')
        ->and($results[0]->toArray())->toHaveKeys(['id', 'slug', 'locale', 'type', 'title', 'excerpt', 'score']);
});

it('filters search by locale, type and category', function (): void {
    $category = KbCategory::query()->create(['name' => 'Billing']);
    article('Refund policy', 'Refunds take five days.', ['type' => ArticleType::Faq, 'categoryId' => $category->getKey()]);
    article('Refund howto', 'How to ask for a refund.');
    article('Rimborso', 'Il refund richiede cinque giorni.', ['locale' => 'it']);

    $public = KnowledgeAudience::public();
    $kb = Ticketing::knowledge();

    expect(resultTitles($kb->search('refund', $public, 'it')))->toBe(['Rimborso'])
        ->and(resultTitles($kb->search('refund', $public, type: ArticleType::Faq)))->toBe(['Refund policy'])
        ->and(resultTitles($kb->search('refund', $public, categoryId: $category->getKey())))->toBe(['Refund policy'])
        ->and($kb->search('refund', $public, limit: 1))->toHaveCount(1)
        ->and($kb->suggest('refund', $public, 'en', 5))->toHaveCount(2)
        ->and($kb->search('a', $public))->toBe([])
        ->and($kb->categories()->pluck('slug')->all())->toBe(['billing']);
});

it('matches non-ASCII words whatever their case', function (): void {
    article('Città e Privacy', 'Le PERSONE possono chiedere la cancellazione.');

    $public = KnowledgeAudience::public();

    expect(resultTitles(Ticketing::knowledge()->search('città', $public)))->toBe(['Città e Privacy'])
        ->and(resultTitles(Ticketing::knowledge()->search('CITTÀ persone', $public)))->toBe(['Città e Privacy']);
});

it('finds a published article by slug with a locale fallback', function (): void {
    $english = article('Contact us', 'Write to us.');
    article('Hidden draft', 'Draft.', publish: false);

    $public = KnowledgeAudience::public();

    expect(Ticketing::knowledge()->find('contact-us', $public, 'fr')?->getKey())->toBe($english->getKey())
        ->and(Ticketing::knowledge()->find('hidden-draft', $public))->toBeNull()
        ->and(Ticketing::knowledge()->find('missing', $public))->toBeNull();
});

it('records one vote per voter and moves counters on a change', function (): void {
    Event::fake([ArticleFeedbackRecorded::class]);
    $article = article('Helpful?', 'Body');
    $kb = Ticketing::knowledge();
    $voter = makeUser();

    $kb->feedback($article, true, $voter);
    $kb->feedback($article, true, $voter);
    $kb->feedback($article, false, 'session-abc', 'Did not help');
    $kb->feedback($article, true, 'session-abc');

    $article->refresh();

    expect($article->helpful_count)->toBe(2)
        ->and($article->not_helpful_count)->toBe(0)
        ->and($article->helpfulness())->toBe(1.0)
        ->and($article->feedback()->count())->toBe(2);

    Event::assertDispatchedTimes(ArticleFeedbackRecorded::class, 3);
});

it('reports no helpfulness without votes', function (): void {
    expect(article('Fresh', 'Body')->helpfulness())->toBeNull();
});

it('links articles to tickets once, audited, within the tenant', function (): void {
    Event::fake([ArticleLinkedToTicket::class]);
    $article = article('Fix printer', 'Turn it off and on.');
    $ticket = Ticketing::open(type: 'support', title: 'Printer');
    $agent = makeUser();

    $link = Ticketing::knowledge()->link($article, $ticket, ArticleLinkRelation::ResolvedBy, $agent);
    Ticketing::knowledge()->link($article, $ticket, ArticleLinkRelation::ResolvedBy, $agent);

    expect($link->relation)->toBe(ArticleLinkRelation::ResolvedBy)
        ->and(Ticketing::knowledge()->linksFor($ticket))->toHaveCount(1)
        ->and($ticket->articleLinks()->count())->toBe(1)
        ->and($ticket->activities()->where('event', 'article.linked')->count())->toBe(1);

    Event::assertDispatchedTimes(ArticleLinkedToTicket::class, 1);

    $context = app(TenantContext::class);
    $foreign = $context->forTenant(9, fn () => article('Foreign', 'Body'));

    expect(fn () => Ticketing::knowledge()->link($foreign, $ticket))->toThrow(CrossTenantException::class);
});

it('keeps each tenant\'s knowledge base apart', function (): void {
    $context = app(TenantContext::class);
    $context->forTenant(1, fn () => article('Tenant one secret', 'Alpha guide'));
    $context->forTenant(2, fn () => article('Tenant two secret', 'Beta guide'));

    $context->forTenant(1, function (): void {
        expect(resultTitles(Ticketing::knowledge()->search('secret', KnowledgeAudience::agent())))->toBe(['Tenant one secret']);
    });
});

it('clears keywords on an explicit empty list and keeps them when omitted', function (): void {
    $article = article('Keywords', 'Body', ['keywords' => ['one', 'two']], publish: false);
    $kb = Ticketing::knowledge();

    expect($kb->save(new ArticleData(title: 'Keywords v2'), $article)->keywords)->toBe('one, two')
        ->and($kb->save(new ArticleData(keywords: []), $article)->keywords)->toBeNull();
});

it('keeps votes but drops the ticket link and comment when the ticket is erased or the voter anonymised', function (): void {
    config()->set('ticketing.gdpr.retention', [['type' => '*', 'after_days' => 30, 'action' => 'delete']]);
    $article = article('Votes', 'Body');
    $ticket = Ticketing::open(type: 'support', title: 'x');
    $contact = Ticketing::contacts()->remember('jane@example.com');

    Ticketing::knowledge()->feedback($article, true, 'session-1', 'My order 123 for Jane', $ticket);
    Ticketing::knowledge()->feedback($article, false, $contact, 'Jane here, still broken');

    Ticketing::transition($ticket, 'close');
    $this->travel(31)->days();
    $this->artisan('ticketing:prune')->assertSuccessful();
    Ticketing::contacts()->anonymize($contact);

    $feedback = $article->feedback()->orderBy('id')->get();

    expect($feedback)->toHaveCount(2)
        ->and($feedback[0]->ticket_id)->toBeNull()
        ->and($feedback[0]->comment)->toBeNull()
        ->and($feedback[1]->comment)->toBeNull()
        ->and($article->refresh()->helpful_count)->toBe(1)
        ->and($article->not_helpful_count)->toBe(1);
});

it('searches numeric terms', function (): void {
    article('Error 500 on checkout', 'A 500 error means the payment provider timed out.');

    expect(resultTitles(Ticketing::knowledge()->search('error 500', KnowledgeAudience::public())))->toBe(['Error 500 on checkout'])
        ->and(resultTitles(Ticketing::knowledge()->search('2026', KnowledgeAudience::public())))->toBe([]);
});

it('moves an article to another locale, re-checking the slug there', function (): void {
    article('Billing', 'B', ['locale' => 'it'], publish: false);
    $wrong = article('Billing', 'B', publish: false);

    $moved = Ticketing::knowledge()->save(new ArticleData(locale: 'it'), $wrong);

    expect($moved->locale)->toBe('it')
        ->and($moved->slug)->toBe('billing-2');
});

it('checks an updated category against the article\'s own tenant', function (): void {
    $context = app(TenantContext::class);
    [$article, $own] = $context->forTenant(1, fn () => [article('A', 'B', publish: false), KbCategory::query()->create(['name' => 'Own'])]);
    $foreign = $context->forTenant(2, fn () => KbCategory::query()->create(['name' => 'Foreign']));

    $context->forTenant(2, function () use ($article, $own, $foreign): void {
        expect(fn () => Ticketing::knowledge()->save(new ArticleData(categoryId: $foreign->getKey()), $article))
            ->toThrow(InvalidArgumentException::class);

        expect(Ticketing::knowledge()->save(new ArticleData(categoryId: $own->getKey()), $article)->category_id)->toBe($own->getKey());
    });
});

it('retries the insert when a concurrent save took the slug', function (): void {
    $attempts = 0;

    // A competing request commits the same slug between this save's check and
    // its insert: the unique slug_key refuses the insert once.
    KbArticle::creating(function (KbArticle $article) use (&$attempts): void {
        if ($article->title === 'Race' && ++$attempts === 1) {
            throw new UniqueConstraintViolationException('testing', 'insert into kb_articles', [], new PDOException('UNIQUE constraint failed: kb_articles.slug_key'));
        }
    });

    $article = article('Race', 'Body', publish: false);

    expect($attempts)->toBe(2)
        ->and($article->exists)->toBeTrue()
        ->and($article->slug_key)->toBe(SaveArticle::slugKey(null, 'en', $article->slug));
});

it('enforces one shared slug per locale at the database level', function (): void {
    article('Shared', 'Body', publish: false);

    expect(fn () => KbArticle::query()->create([
        'slug' => 'shared', 'slug_key' => SaveArticle::slugKey(null, 'en', 'shared'),
        'locale' => 'en', 'title' => 'Dup', 'body' => 'B', 'type' => 'article', 'status' => 'draft',
        'visibility' => 'public', 'version' => 1,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('lets every agent write when tenancy is off', function (): void {
    $article = article('Single tenant', 'Body', publish: false);
    $agent = makeUser(['tenant_id' => 5]);
    config()->set('ticketing.tenancy.enabled', false);

    expect(Gate::forUser($agent)->allows('update', $article))->toBeTrue()
        ->and(Gate::forUser($agent)->allows('view', $article))->toBeTrue();
});
