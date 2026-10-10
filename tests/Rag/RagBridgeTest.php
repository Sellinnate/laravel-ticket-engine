<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Actions\Knowledge\SaveArticle;
use Selli\Ticketing\Ai\AiManager;
use Selli\Ticketing\Ai\AiRequest;
use Selli\Ticketing\Ai\EscalationPolicy;
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Integrations\RagEngine\RagAiDriver;
use Selli\Ticketing\Integrations\RagEngine\RagHit;
use Selli\Ticketing\Integrations\RagEngine\RagKnowledge;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Tests\Fixtures\TestRequester;
use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Managers\LlmManager;
use Sellinnate\RagEngine\Models\Document;

function kb(string $title, string $body, KnowledgeVisibility $visibility = KnowledgeVisibility::Public, ?Model $audience = null): KbArticle
{
    $article = Ticketing::knowledge()->save(new ArticleData(title: $title, body: $body, visibility: $visibility, audience: $audience));

    return Ticketing::knowledge()->publish($article);
}

/**
 * @return list<string> titles found
 */
function ragFind(string $question, KnowledgeAudience $audience, int|string|null $tenant = null): array
{
    return array_map(fn (RagHit $hit): string => $hit->title, app(RagKnowledge::class)->search($question, $audience, $tenant));
}

function ragDocuments(string $key): int
{
    return Document::query()->withoutGlobalScopes()->where('metadata->document_key', $key)->count();
}

it('indexes published articles and finds them only for the audiences that may read them', function (): void {
    kb('Reset your password', 'Open settings, choose security, then reset password with the emailed link.');
    kb('Password reset runbook', 'Internal runbook: reset password from the admin console and audit the account.', KnowledgeVisibility::Internal);
    kb('Password policy for customers', 'Customers choose a password reset every ninety days from settings security.', KnowledgeVisibility::Customer);
    $client = TestRequester::query()->create(['name' => 'Acme']);
    kb('Acme password reset', 'Acme staff reset password through the single sign on portal settings.', KnowledgeVisibility::Public, $client);
    Ticketing::knowledge()->save(new ArticleData(title: 'Draft about password reset', body: 'Draft password reset settings security text.'));

    $question = 'How do I reset my password from settings security?';

    expect(ragFind($question, KnowledgeAudience::public()))->toBe(['Reset your password'])
        ->and(ragFind($question, KnowledgeAudience::customer()))->toEqualCanonicalizing(['Reset your password', 'Password policy for customers'])
        ->and(ragFind($question, KnowledgeAudience::public($client)))->toEqualCanonicalizing(['Reset your password', 'Acme password reset'])
        ->and(ragFind($question, KnowledgeAudience::agent()))->toContain('Password reset runbook', 'Acme password reset')
        ->and(ragFind($question, KnowledgeAudience::agent()))->not->toContain('Draft about password reset');
});

it('replaces the indexed version of an edited article and drops an archived one', function (): void {
    $article = kb('Shipping times', 'Parcels leave the warehouse within two working days.');

    Ticketing::knowledge()->save(new ArticleData(body: 'Parcels leave the warehouse within five working days via courier.'), $article);

    $hits = app(RagKnowledge::class)->search('When do parcels leave the warehouse?', KnowledgeAudience::public(), null);

    expect($hits)->toHaveCount(1)
        ->and($hits[0]->excerpt)->toContain('five working days')
        ->and(ragDocuments('kb_article:'.$article->getKey()))->toBe(1);

    Ticketing::knowledge()->archive($article);

    expect(ragFind('When do parcels leave the warehouse?', KnowledgeAudience::agent()))->toBe([])
        ->and(ragDocuments('kb_article:'.$article->getKey()))->toBe(0);
});

it('keeps every tenant in its own RAG tenant, shared articles visible to all', function (): void {
    $context = app(TenantContext::class);
    $context->forTenant(1, fn () => kb('Invoices of tenant one', 'Download invoices from billing history monthly statements.'));
    $context->forTenant(2, fn () => kb('Invoices of tenant two', 'Download invoices from billing history yearly statements.'));
    kb('Invoices everywhere', 'Download invoices from billing history any statements.');

    $question = 'Where do I download invoices from billing history?';

    expect(ragFind($question, KnowledgeAudience::public(), 1))->toEqualCanonicalizing(['Invoices of tenant one', 'Invoices everywhere'])
        ->and(ragFind($question, KnowledgeAudience::public(), 2))->toEqualCanonicalizing(['Invoices of tenant two', 'Invoices everywhere'])
        ->and(app(RagKnowledge::class)->ragTenant(1))->toBe('tk-n-1')
        ->and(app(RagKnowledge::class)->ragTenant(null))->toBe('tk-shared');

    config()->set('ticketing.tenancy.allow_shared', false);
    app()->forgetInstance(TenantContext::class);
    expect(ragFind($question, KnowledgeAudience::public(), 1))->toBe(['Invoices of tenant one']);
});

it('maps every tenant id to its own RAG tenant', function (): void {
    $rag = app(RagKnowledge::class);
    $ids = ['acme.eu', 'acme-eu', 'acme_eu', 'shared', 5, '5', str_repeat('x', 60), str_repeat('x', 61), 'École'];
    $mapped = array_map(fn (int|string $id): string => $rag->ragTenant($id), $ids);

    expect(array_unique(array_merge($mapped, [$rag->ragTenant(null)])))->toHaveCount(count($ids))  // 5 and '5' are one tenant
        ->and($rag->ragTenant('shared'))->not->toBe($rag->ragTenant(null))
        ->and($mapped)->each->toMatch('/^[A-Za-z0-9_-]{1,64}$/');
});

it('never hands out an article changed since it was indexed', function (): void {
    $article = kb('Discount codes', 'Use the discount codes printed on your loyalty card at checkout.');
    $question = 'Where are the discount codes for checkout loyalty card?';

    expect(ragFind($question, KnowledgeAudience::public()))->toBe(['Discount codes']);

    // Made internal behind the index's back (its sync job not run yet).
    KbArticle::query()->whereKey($article->getKey())->update(['visibility' => 'internal']);

    $answer = (new RagAiDriver(app(RagKnowledge::class), ['classify' => false]))
        ->answer(new AiRequest(question: $question, audience: KnowledgeAudience::public()));

    expect(ragFind($question, KnowledgeAudience::public()))->toBe([])
        ->and($answer->isEmpty())->toBeTrue()
        ->and(ragFind($question, KnowledgeAudience::agent()))->toBe(['Discount codes']);
});

it('never uses the excerpt of an older version of an article', function (): void {
    $article = kb('Wire transfer', 'Pay by wire transfer to the bank account number listed on the invoice.');
    $question = 'wire transfer bank account number invoice';

    // Edited (say, a wrong bank account removed) while the re-index is still pending.
    config()->set('ticketing.rag.index.articles', false);
    Ticketing::knowledge()->save(new ArticleData(body: 'Pay by wire transfer: the bank details are in your customer area.'), $article);

    expect(ragFind($question, KnowledgeAudience::public()))->toBe([]);

    config()->set('ticketing.rag.index.articles', true);
    app(RagKnowledge::class)->indexArticle($article->refresh());

    expect(ragFind('wire transfer bank details customer area', KnowledgeAudience::public()))->toBe(['Wire transfer']);
});

it('keeps the requested locale for every tenant searched', function (): void {
    config()->set('ticketing.knowledge.default_locale', 'en');
    app(TenantContext::class)->forTenant(1, fn () => Ticketing::knowledge()->publish(Ticketing::knowledge()->save(new ArticleData(title: 'Refund policy', body: 'Refund requests within thirty days, refund policy details.', locale: 'en'))));
    Ticketing::knowledge()->publish(Ticketing::knowledge()->save(new ArticleData(title: 'Politique de remboursement', body: 'Refund requests within thirty days, politique de remboursement.', locale: 'fr')));

    $found = array_map(fn (RagHit $hit): string => $hit->title, app(RagKnowledge::class)->search('refund requests within thirty days', KnowledgeAudience::public(), 1, 'fr'));

    expect($found)->toEqualCanonicalizing(['Refund policy', 'Politique de remboursement']);
});

it('indexes resolved tickets as internal knowledge, without internal notes', function (): void {
    $customer = TestRequester::query()->create(['name' => 'Customer']);
    $ticket = Ticketing::open(type: 'support', title: 'Printer offline after update', requester: $customer);
    Ticketing::postMessage($ticket, $customer, 'The office printer went offline after the driver update.');
    Ticketing::postMessage($ticket, makeUser(), 'Reinstall the printer driver from the vendor site, then restart the spooler.');
    Ticketing::postMessage($ticket, makeUser(), 'Secret escalation contact: ops pager 555.', MessageVisibility::Internal);

    expect(app(RagKnowledge::class)->similarTickets('printer offline after driver update', null))->toBe([]);

    Ticketing::transition($ticket, 'resolve');

    $similar = app(RagKnowledge::class)->similarTickets('printer offline after driver update', null);

    expect($similar)->toHaveCount(1)
        ->and($similar[0]->type)->toBe(RagKnowledge::TYPE_TICKET)
        ->and($similar[0]->id)->toBe((string) $ticket->getKey())
        ->and($similar[0]->excerpt)->toContain('Reinstall the printer driver')
        ->and($similar[0]->excerpt)->not->toContain('pager')
        // Customers never get tickets.
        ->and(app(RagKnowledge::class)->search('printer offline after driver update', KnowledgeAudience::customer(), null, types: [RagKnowledge::TYPE_TICKET]))->toBe([]);

    // A late public reply on the resolved ticket joins the indexed conversation.
    Ticketing::postMessage($ticket->refresh(), makeUser(), 'Follow-up: the vendor released a fixed driver, version 2.1.');

    expect(app(RagKnowledge::class)->similarTickets('printer offline after driver update', null)[0]->excerpt)->toContain('version 2.1');

    // Reopened: not resolved knowledge any more — even before its sync job ran.
    config()->set('ticketing.rag.index.tickets', false);
    Ticketing::transition($ticket->refresh(), 'reopen');
    expect(ragDocuments('ticket:'.$ticket->getKey()))->toBe(1)
        ->and(app(RagKnowledge::class)->similarTickets('printer offline after driver update', null))->toBe([]);
});

it('purges the vectors of erased tickets and anonymised requesters', function (): void {
    $customer = Ticketing::contacts()->remember('jane@example.test', 'Jane');
    $ticket = Ticketing::open(type: 'support', title: 'Refund for order', requester: $customer);
    Ticketing::postMessage($ticket, $customer, 'Please refund my order, Jane Doe, card ending 4242.');
    Ticketing::transition($ticket, 'resolve');
    $other = Ticketing::open(type: 'support', title: 'Delivery delayed parcel');
    Ticketing::postMessage($other, null, 'My parcel delivery is delayed by a week.');
    Ticketing::transition($other, 'close');

    expect(ragDocuments('ticket:'.$ticket->getKey()))->toBe(1);

    Ticketing::contacts()->anonymize($customer);

    expect(ragDocuments('ticket:'.$ticket->getKey()))->toBe(0)
        ->and(ragDocuments('ticket:'.$other->getKey()))->toBe(1);

    // A backfill (or a late job) does not bring the erased conversation back.
    $this->artisan('ticketing:rag:index', ['--tickets' => true])->assertSuccessful();
    app(RagKnowledge::class)->indexTicket($ticket->refresh());

    expect(ragDocuments('ticket:'.$ticket->getKey()))->toBe(0);

    config()->set('ticketing.gdpr.retention', [['type' => '*', 'after_days' => 1, 'action' => 'delete']]);
    $this->travel(2)->days();
    $this->artisan('ticketing:prune')->assertSuccessful();

    expect(ragDocuments('ticket:'.$other->getKey()))->toBe(0);
});

it('never hands out an excluded conversation, even if its vectors survived', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Broken screen replacement');
    Ticketing::postMessage($ticket, null, 'My phone screen is broken, I need a replacement screen.');
    Ticketing::transition($ticket, 'resolve');

    expect(app(RagKnowledge::class)->similarTickets('broken phone screen replacement', null))->toHaveCount(1);

    // The purge failed after the exclusion was recorded.
    app(AuditLogger::class)->record(ticket: $ticket, event: RagKnowledge::EXCLUDED);

    expect(ragDocuments('ticket:'.$ticket->getKey()))->toBe(1)
        ->and(app(RagKnowledge::class)->similarTickets('broken phone screen replacement', null))->toBe([]);
});

it('cleans up merged tickets and keeps an erased conversation out through a merge', function (): void {
    // A shared ticket merged into a tenant one: purged from the shared RAG tenant.
    $shared = Ticketing::open(type: 'support', title: 'Laptop battery drains');
    Ticketing::postMessage($shared, null, 'The laptop battery drains overnight while sleeping.');
    Ticketing::transition($shared, 'resolve');
    $target = app(TenantContext::class)->forTenant(3, fn () => Ticketing::open(type: 'support', title: 'Battery issue'));

    expect(ragDocuments('ticket:'.$shared->getKey()))->toBe(1);

    Ticketing::for($target)->mergeFrom([$shared]);

    expect(ragDocuments('ticket:'.$shared->getKey()))->toBe(0);

    // An erased requester's ticket merged into a resolved one: the target stays out.
    $customer = Ticketing::contacts()->remember('erased@example.test');
    $erased = Ticketing::open(type: 'support', title: 'Account deletion', requester: $customer);
    Ticketing::postMessage($erased, $customer, 'Delete my account and my private notes please.');
    Ticketing::contacts()->anonymize($customer);
    $resolved = Ticketing::open(type: 'support', title: 'Account help');
    Ticketing::postMessage($resolved, null, 'General account help request.');
    Ticketing::transition($resolved, 'resolve');

    Ticketing::for($resolved->refresh())->mergeFrom([$erased]);

    expect(ragDocuments('ticket:'.$resolved->getKey()))->toBe(0)
        ->and(app(RagKnowledge::class)->excluded($resolved))->toBeTrue();

    // Split out of it, then resolved: still out.
    $split = Ticketing::for($resolved->refresh())->split([$resolved->messages()->oldest('id')->value('id')]);
    Ticketing::transition($split, 'resolve');

    expect(app(RagKnowledge::class)->excluded($split))->toBeTrue()
        ->and(ragDocuments('ticket:'.$split->getKey()))->toBe(0);
});

it('reindexes a resolved ticket whose messages were split out', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Two problems');
    Ticketing::postMessage($ticket, null, 'Monitor flickers after waking from sleep.');
    $moved = Ticketing::postMessage($ticket, null, 'Separately the keyboard backlight never turns on.');
    Ticketing::transition($ticket, 'resolve');

    Ticketing::for($ticket->refresh())->split([$moved->getKey()]);

    expect(app(RagKnowledge::class)->similarTickets('keyboard backlight never turns on', null))->toBe([])
        ->and(app(RagKnowledge::class)->similarTickets('monitor flickers after waking from sleep', null))->toHaveCount(1);
});

it('keeps the opening and the latest replies of a long conversation', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Router keeps rebooting');
    Ticketing::postMessage($ticket, null, 'My router keeps rebooting every hour.');
    foreach (range(1, 205) as $i) {
        Ticketing::postMessage($ticket, null, "No {$i}.");
    }
    Ticketing::postMessage($ticket, makeUser(), 'Solution: replace the faulty power adapter of the router.');
    Ticketing::transition($ticket, 'resolve');

    $content = Rag::forTenant('tk-shared', fn () => Rag::ingestor()->content(
        Document::query()->withoutGlobalScopes()->where('metadata->document_key', 'ticket:'.$ticket->getKey())->sole(),
    ));

    expect($content)->toContain('My router keeps rebooting every hour.')
        ->and($content)->toContain('replace the faulty power adapter')
        ->and($content)->not->toContain('No 7.');
});

it('powers the knowledge-base search, re-checked against the database', function (): void {
    $article = kb('Two-factor login', 'Enable two-factor login with an authenticator app from account security.');

    $results = Ticketing::knowledge()->search('how to enable two-factor login with an authenticator', KnowledgeAudience::public());

    expect($results)->toHaveCount(1)
        ->and($results[0]->article->is($article))->toBeTrue();

    // Moved to another type behind the bridge's back: no longer an answer for a type filter.
    KbArticle::query()->whereKey($article->getKey())->update(['type' => 'faq']);
    expect(Ticketing::knowledge()->search('how to enable two-factor login with an authenticator', KnowledgeAudience::public(), type: ArticleType::Article))->toBe([]);
    KbArticle::query()->whereKey($article->getKey())->update(['type' => 'article']);

    // Unpublished behind the bridge's back (no event): the stale vector is not trusted.
    KbArticle::query()->whereKey($article->getKey())->update(['status' => 'draft']);

    expect(Ticketing::knowledge()->search('how to enable two-factor login with an authenticator', KnowledgeAudience::public()))->toBe([]);
});

it('answers customers from the knowledge base with citations', function (): void {
    config()->set('ticketing.ai.driver', 'rag-engine');
    config()->set('ticketing.ai.drivers.rag-engine.classify', false);
    config()->set('ticketing.ai.mode', 'auto');
    config()->set('ticketing.ai.escalation.min_confidence', 0.3);
    app(AiManager::class)->forgetDrivers();
    $article = kb('Change delivery address', 'Change the delivery address of an order from orders, edit shipping details.');
    kb('Delivery address runbook', 'Internal: change delivery address in the warehouse console orders shipping.', KnowledgeVisibility::Internal);

    $customer = Ticketing::contacts()->remember('jane@example.test');
    $ticket = Ticketing::open(type: 'support', title: 'Address', requester: $customer);
    Ticketing::postMessage($ticket, $customer, 'How can I change the delivery address of my order shipping details?');

    $reply = $ticket->messages()->where('source', MessageSource::Ai->value)->sole();

    expect($reply->body)->toStartWith('ANSWER: You are a customer-support assistant')
        ->and(array_column($reply->meta['ai']['sources'], 'id'))->toBe([(string) $article->getKey()])
        ->and($ticket->refresh()->ai_status)->toBe(AiStatus::Active);
});

it('hands over when the knowledge base has nothing, or the model says it cannot answer', function (): void {
    $driver = new RagAiDriver(app(RagKnowledge::class), ['classify' => false]);
    $request = fn (string $question): AiRequest => new AiRequest(question: $question, audience: KnowledgeAudience::customer());

    expect($driver->answer($request('Completely unrelated gardening question about tomatoes'))->isEmpty())->toBeTrue();

    kb('Cancel subscription', 'Cancel your subscription from billing plans at any time.');
    app(LlmManager::class)->extend('refusing', fn () => new class implements Llm
    {
        public function generate(string $prompt, array $options = []): string
        {
            return RagAiDriver::NO_ANSWER;
        }

        public function stream(string $prompt, array $options = []): iterable
        {
            yield RagAiDriver::NO_ANSWER;
        }

        public function model(): string
        {
            return 'refusing';
        }
    });
    config()->set('rag-engine.llms.refusing', ['driver' => 'refusing']);

    $refusing = new RagAiDriver(app(RagKnowledge::class), ['llm' => 'refusing']);
    $answer = $refusing->answer($request('How do I cancel my subscription from billing plans?'));

    expect($answer->isEmpty())->toBeTrue()
        ->and($answer->usage->model)->toBe('refusing')
        ->and(app(EscalationPolicy::class)->afterAnswer($answer))->toBe(EscalationReason::NoSources);
});

it('classifies through the model and survives a reply that is not JSON', function (): void {
    app(LlmManager::class)->extend('classifier', fn () => new class implements Llm
    {
        public string $reply = '{"language": "it", "sentiment": "negative", "priority": "urgent", "category": "billing", "wants_human": true, "topics": ["billing"]}';

        public function generate(string $prompt, array $options = []): string
        {
            return 'Sure! '.$this->reply;
        }

        public function stream(string $prompt, array $options = []): iterable
        {
            yield $this->reply;
        }

        public function model(): string
        {
            return 'classifier';
        }
    });
    config()->set('rag-engine.llms.classifier', ['driver' => 'classifier']);

    $driver = new RagAiDriver(app(RagKnowledge::class), ['llm' => 'classifier']);
    $classification = $driver->classify(new AiRequest(question: 'Voglio parlare con una persona, fattura sbagliata!', audience: KnowledgeAudience::customer()));

    expect($classification->language)->toBe('it')
        ->and($classification->sentiment?->value)->toBe('negative')
        ->and($classification->priority)->toBe(Priority::Urgent)
        ->and($classification->wantsHuman)->toBeTrue()
        ->and($classification->topics)->toBe(['billing']);

    $llm = app(LlmManager::class)->driver('classifier');
    $llm->reply = '{"priority": "normal"}';
    expect($driver->classify(new AiRequest(question: 'Hi', audience: KnowledgeAudience::customer()))->priority)->toBe(Priority::Normal);

    // The default fake LLM does not answer in JSON: no classification.
    expect((new RagAiDriver(app(RagKnowledge::class)))->classify(new AiRequest(question: 'Hello', audience: KnowledgeAudience::customer()))->language)->toBeNull();
});

it('backfills the index from the command', function (): void {
    config()->set('ticketing.rag.enabled', false); // nothing indexed on the way
    $article = KbArticle::query()->create([
        'slug' => 'backfilled', 'slug_key' => SaveArticle::slugKey(null, 'en', 'backfilled'),
        'locale' => 'en', 'title' => 'Backfilled article', 'body' => 'Backfilled article about warranty repairs.', 'type' => 'article',
        'status' => 'published', 'visibility' => 'public', 'version' => 1,
    ]);

    expect(ragDocuments('kb_article:'.$article->getKey()))->toBe(0);

    $this->artisan('ticketing:rag:index', ['--articles' => true])->expectsOutputToContain('articles — 1')->assertSuccessful();

    expect(ragFind('warranty repairs article', KnowledgeAudience::public()))->toBe(['Backfilled article']);
});

it('shreds a whole tenant from the engine', function (): void {
    app(TenantContext::class)->forTenant(5, fn () => kb('Tenant five secret sauce', 'Recipe for the tenant five secret sauce marinade.'));

    app(RagKnowledge::class)->shredTenant(5, 'tenant deleted');

    expect(ragFind('secret sauce marinade recipe', KnowledgeAudience::agent(), 5))->toBe([]);
});
