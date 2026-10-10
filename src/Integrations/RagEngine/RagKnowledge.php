<?php

declare(strict_types=1);

namespace Selli\Ticketing\Integrations\RagEngine;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Selli\Ticketing\Actions\Knowledge\SaveArticle;
use Selli\Ticketing\Ai\AiRequestFactory;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Enums\ArticleStatus;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Gdpr\RequesterTickets;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Security\CryptoShredder;

/**
 * The bridge to sellinnate/rag-engine: keeps published knowledge-base
 * articles and resolved tickets in a RAG index, and searches it for an
 * audience.
 *
 * Every document carries filterable metadata — type, visibility, audience
 * scope, locale, article type, category — and every search filters on them
 * with positive conditions only (`in`), so a document whose metadata is
 * missing or unexpected matches nothing: an internal runbook can never reach
 * a customer. Resolved tickets are internal knowledge (agents only). Each
 * ticketing tenant maps to its own RAG tenant (`<prefix><tenant>`, shared
 * rows to `<prefix>shared`), which the engine isolates on its own.
 */
class RagKnowledge
{
    public const TYPE_ARTICLE = 'kb_article';

    public const TYPE_TICKET = 'ticket';

    /** The audit event that keeps a ticket out of the index for good. */
    public const EXCLUDED = 'rag.excluded';

    public function __construct(
        protected TenantContext $tenants,
        protected AiRequestFactory $requests,
    ) {}

    /**
     * The RAG tenant of a ticketing tenant — one-to-one, and safe as a vector
     * store namespace ([A-Za-z0-9_-], at most 64 characters): `<prefix>shared`
     * for shared rows, `<prefix>n-<id>` for a plain id (letters, digits,
     * underscores), `<prefix>b-<base64url>` for any other one, and a hash for
     * an id too long to encode.
     */
    public function ragTenant(int|string|null $tenant): string
    {
        $prefix = (string) config('ticketing.rag.tenant_prefix', 'tk-');

        if ($tenant === null || (string) $tenant === '') {
            return $prefix.'shared';
        }

        $id = (string) $tenant;

        if (preg_match('/^[A-Za-z0-9_]{1,48}$/', $id) === 1) {
            return $prefix.'n-'.$id;
        }

        $encoded = rtrim(strtr(base64_encode($id), '+/', '-_'), '=');

        return strlen($encoded) <= 48 ? $prefix.'b-'.$encoded : $prefix.'h-'.substr(hash('sha256', $id), 0, 48);
    }

    public function namespace(): string
    {
        $namespace = config('ticketing.rag.namespace', 'ticketing');

        return is_string($namespace) && $namespace !== '' ? $namespace : 'ticketing';
    }

    /**
     * Index a published article (any other status removes it).
     */
    public function indexArticle(KbArticle $article): void
    {
        $this->syncArticle($article->getKey(), $article->getAttribute($article->getTenantColumn()));
    }

    /**
     * Bring one article's index entry up to date with the database, read
     * under the document's lock — a worker holding an older snapshot can
     * never overwrite a newer version.
     */
    public function syncArticle(int|string $id, int|string|null $tenant): void
    {
        $this->locked($tenant, self::TYPE_ARTICLE.':'.$id, function () use ($id, $tenant): void {
            $article = Ticketing::kbArticleModel()::query()->withoutTenancy()->withTrashed()->find($id);

            $article instanceof KbArticle
                ? $this->writeArticle($article)
                : $this->purge($tenant, self::TYPE_ARTICLE.':'.$id);
        });
    }

    protected function writeArticle(KbArticle $article): void
    {
        $tenant = $article->getAttribute($article->getTenantColumn());

        if ($article->status !== ArticleStatus::Published || $article->trashed()) {
            $this->purge($tenant, self::TYPE_ARTICLE.':'.$article->getKey());

            return;
        }

        $body = $article->body_format === BodyFormat::Html ? $this->plain($article->body) : $article->body;
        $text = trim(implode("\n\n", array_filter([$article->title, $article->summary, $body], fn (?string $part): bool => $part !== null && trim($part) !== '')));

        $this->put($tenant, self::TYPE_ARTICLE, $article->getKey(), $article->title, $text, [
            'visibility' => $article->visibility->value,
            // "*" for everyone, or the one host scope (a client) it is restricted to.
            'audience' => $article->audience_type !== null && $article->audience_id !== null
                ? $article->audience_type.':'.$article->audience_id
                : '*',
            'locale' => $article->locale,
            'article_type' => $article->type->value,
            'category' => $article->category_id !== null ? (string) $article->category_id : '',
            'slug' => $article->slug,
            'url' => $this->articleUrl($article) ?? '',
            // Checked at search time: an excerpt of an older version is never used.
            'version' => (string) $article->version,
        ]);
    }

    public function forgetArticle(KbArticle $article): void
    {
        $this->forgetArticleById($article->getKey(), $article->getAttribute($article->getTenantColumn()));
    }

    public function forgetArticleById(int|string $articleId, int|string|null $tenant): void
    {
        $key = self::TYPE_ARTICLE.':'.$articleId;

        $this->locked($tenant, $key, fn () => $this->purge($tenant, $key));
    }

    /**
     * Index a resolved or closed ticket — its title and public conversation,
     * never an internal note — as internal knowledge for agents. Any other
     * ticket is removed.
     */
    public function indexTicket(Ticket $ticket): void
    {
        $this->syncTicket($ticket->getKey(), $ticket->getAttribute($ticket->getTenantColumn()));
    }

    /**
     * Bring one ticket's index entry up to date with the database, read
     * under the document's lock (see syncArticle()).
     */
    public function syncTicket(int|string $id, int|string|null $tenant): void
    {
        $this->locked($tenant, self::TYPE_TICKET.':'.$id, function () use ($id, $tenant): void {
            // Trashed ones too (a merged-away ticket): purged in their own tenant.
            $query = Ticketing::ticketModel()::query()->withoutTenancy();
            $ticket = (RequesterTickets::softDeletes(Ticketing::ticketModel()) ? $query->withTrashed() : $query)->find($id);

            $ticket instanceof Ticket
                ? $this->writeTicket($ticket)
                : $this->purge($tenant, self::TYPE_TICKET.':'.$id);
        });
    }

    protected function writeTicket(Ticket $ticket): void
    {
        $tenant = $ticket->getAttribute($ticket->getTenantColumn());

        // An erased requester's conversation never comes back (a backfill, a
        // late job): the exclusion is recorded in the ticket's audit trail.
        if (! $this->requests->isClosed($ticket) || $ticket->trashed() || $this->excluded($ticket)) {
            $this->purge($tenant, self::TYPE_TICKET.':'.$ticket->getKey());

            return;
        }

        $lines = [$ticket->title];

        // A long conversation keeps its opening (the problem) and its latest
        // 199 messages (where the solution is).
        $public = fn () => $ticket->messages()->withoutTenancy()->with('author')->where('visibility', MessageVisibility::Public->value);
        $messages = $public()->latest()->orderByDesc((new (Ticketing::ticketMessageModel()))->getKeyName())->limit(199)->get()->reverse()->values();
        $first = $public()->oldest()->orderBy((new (Ticketing::ticketMessageModel()))->getKeyName())->first();

        if ($first instanceof TicketMessage && ! $messages->contains(fn (TicketMessage $message): bool => $message->is($first))) {
            $messages->prepend($first);
        }

        /** @var TicketMessage $message */
        foreach ($messages as $message) {
            $role = $message->author instanceof CanActOnTickets ? 'Agent' : ($message->author_id === null && $message->source->value === 'ai' ? 'Assistant' : 'Customer');
            $body = $message->body_format === BodyFormat::Html ? $this->plain($message->body) : $message->body;
            $lines[] = $role.': '.trim($body);
        }

        $this->put($tenant, self::TYPE_TICKET, $ticket->getKey(), $ticket->reference.' '.$ticket->title, implode("\n\n", $lines), [
            'visibility' => KnowledgeVisibility::Internal->value,
            'audience' => '*',
            'locale' => $ticket->language ?? SaveArticle::defaultLocale(),
            'reference' => $ticket->reference,
        ]);
    }

    public function forgetTicket(int|string $ticketId, int|string|null $tenant): void
    {
        $key = self::TYPE_TICKET.':'.$ticketId;

        $this->locked($tenant, $key, fn () => $this->purge($tenant, $key));
    }

    /**
     * GDPR: remove the indexed conversations of a requester (in every tenant
     * they appear in) — their words must not survive in the vectors — and
     * keep them out for good (an audited exclusion).
     */
    public function forgetTicketsOf(Model $requester): int
    {
        $count = 0;

        // First every exclusion — durable, and already enough to keep the
        // conversations out of every search — then the fallible purges.
        $tickets = [];

        RequesterTickets::query($requester)->each(function (Ticket $ticket) use (&$tickets): void {
            if (! $this->excluded($ticket)) {
                app(AuditLogger::class)->record(ticket: $ticket, event: self::EXCLUDED, context: ['reason' => 'requester_anonymized']);
            }

            $tickets[] = [$ticket->getKey(), $ticket->getAttribute($ticket->getTenantColumn())];
        });

        foreach ($tickets as [$id, $tenant]) {
            try {
                // Re-read under the lock: excluded now, so it is purged.
                $this->syncTicket($id, $tenant);
                $count++;
            } catch (\Throwable $exception) {
                // The vector store is down, a lock timed out: retried on the queue.
                report($exception);
                RagSyncJob::dispatch(self::TYPE_TICKET, $id, $tenant);
            }
        }

        return $count;
    }

    /**
     * Carry the exclusion of merged-in tickets over to the target — or of a
     * split ticket over to the new one — whose conversation now holds their
     * messages.
     *
     * @param  list<int|string>  $sourceIds
     */
    public function inheritExclusions(Ticket $target, array $sourceIds): void
    {
        if ($sourceIds === [] || $this->excluded($target)) {
            return;
        }

        $excluded = Ticketing::ticketActivityModel()::query()->withoutTenancy()
            ->whereIn('ticket_id', $sourceIds)
            ->whereIn('event', [self::EXCLUDED, 'requester.anonymized'])
            ->exists();

        if ($excluded) {
            app(AuditLogger::class)->record(ticket: $target, event: self::EXCLUDED, context: ['reason' => 'merged_excluded_ticket']);
        }
    }

    /**
     * Whether a ticket must stay out of the index: its requester was erased.
     */
    public function excluded(Ticket $ticket): bool
    {
        return $ticket->activities()->withoutTenancy()
            ->whereIn('event', [self::EXCLUDED, 'requester.anonymized'])
            ->exists();
    }

    /**
     * Erase a whole tenant from the RAG engine — vectors, chunks, keys — for
     * good (the engine refuses any later ingest for it).
     */
    public function shredTenant(int|string|null $tenant, ?string $reason = null): void
    {
        $ragTenant = $this->ragTenant($tenant);

        Rag::forTenant($ragTenant, fn () => app(CryptoShredder::class)->shredTenant($ragTenant, $reason));
    }

    /**
     * The documents an audience may read, best first, one hit per document:
     * the tenant's own and — when sharing is on — the shared ones.
     *
     * @param  list<string>  $types
     * @return list<RagHit>
     */
    public function search(
        string $text,
        KnowledgeAudience $audience,
        int|string|null $tenant,
        ?string $locale = null,
        int $limit = 5,
        ?float $minScore = null,
        array $types = [self::TYPE_ARTICLE],
        ?ArticleType $articleType = null,
        int|string|null $categoryId = null,
    ): array {
        if (trim($text) === '' || $types === [] || $limit < 1) {
            return [];
        }

        $minScore ??= (float) config('ticketing.rag.min_score', 0.35);
        $ragTenants = array_unique(array_filter([
            $this->ragTenant($tenant),
            $tenant === null || $this->tenants->allowsShared() ? $this->ragTenant(null) : null,
        ]));

        $best = [];

        foreach ($ragTenants as $ragTenant) {
            /** @var list<SearchHit> $hits */
            $hits = Rag::forTenant($ragTenant, function () use ($text, $audience, $locale, $limit, $minScore, $types, $articleType, $categoryId): array {
                $search = Rag::search($text)
                    ->namespace($this->namespace())
                    // Positive conditions only: unknown or missing metadata never matches.
                    ->where('type', ['in' => $types])
                    ->where('visibility', ['in' => array_map(fn (KnowledgeVisibility $visibility): string => $visibility->value, $audience->visibilities())])
                    ->threshold($minScore)
                    ->topK($limit * 3);

                if (! $audience->agent) {
                    $search->where('audience', ['in' => array_values(array_unique(['*', $audience->scopeKey()]))]);
                }

                if ($locale !== null && $locale !== '') {
                    $search->where('locale', ['in' => array_values(array_unique([$locale, SaveArticle::defaultLocale()]))]);
                }

                if ($articleType !== null) {
                    $search->where('article_type', $articleType->value);
                }

                if ($categoryId !== null) {
                    $search->where('category', (string) $categoryId);
                }

                return $search->get();
            });

            foreach ($hits as $hit) {
                $type = $hit->metadata['type'] ?? null;
                $id = $hit->metadata['source_id'] ?? null;

                if (! is_string($type) || ! is_string($id)) {
                    continue;
                }

                $key = $type.':'.$id;

                if (! isset($best[$key]) || $hit->score > $best[$key]->score) {
                    $url = $hit->metadata['url'] ?? null;
                    $hitLocale = $hit->metadata['locale'] ?? null;
                    $title = $hit->metadata['title'] ?? null;
                    $version = $hit->metadata['version'] ?? null;

                    $best[$key] = new RagHit(
                        type: $type,
                        id: $id,
                        title: is_string($title) ? $title : $id,
                        score: $hit->score,
                        excerpt: mb_strimwidth(trim($hit->content), 0, 600, '…'),
                        url: is_string($url) && $url !== '' ? $url : null,
                        locale: is_string($hitLocale) ? $hitLocale : null,
                        version: is_string($version) ? $version : null,
                    );
                }
            }
        }

        usort($best, fn (RagHit $a, RagHit $b): int => $b->score <=> $a->score);

        return array_slice($this->verified($best, $audience, $tenant), 0, $limit);
    }

    /**
     * Only what the database still allows: the index follows the help desk
     * asynchronously, so an article unpublished, restricted or moved — or a
     * ticket erased — since it was indexed is dropped here, before anyone
     * (a customer, a model's prompt) sees its text.
     *
     * @param  list<RagHit>  $hits
     * @return list<RagHit>
     */
    protected function verified(array $hits, KnowledgeAudience $audience, int|string|null $tenant): array
    {
        $ids = [self::TYPE_ARTICLE => [], self::TYPE_TICKET => []];

        foreach ($hits as $hit) {
            if (isset($ids[$hit->type])) {
                $ids[$hit->type][] = $hit->id;
            }
        }

        $allowed = [self::TYPE_ARTICLE => [], self::TYPE_TICKET => []];

        // Articles: readable by this audience now, and the indexed version is
        // the current one (an edit not synced yet must not leak the old text).
        $versions = [];

        if ($ids[self::TYPE_ARTICLE] !== []) {
            $model = Ticketing::kbArticleModel();
            $articles = $this->inTenant($model::query()->withoutTenancy()->visibleTo($audience), $tenant)
                ->whereKey($ids[self::TYPE_ARTICLE])
                ->get([(new $model)->getKeyName(), 'version']);

            foreach ($articles as $article) {
                $versions[(string) $article->getKey()] = (string) $article->version;
            }

            $allowed[self::TYPE_ARTICLE] = array_map('strval', array_keys($versions));
        }

        // Tickets are agents' knowledge only, and only while still resolved
        // (a reopened ticket's answer did not hold).
        if ($ids[self::TYPE_TICKET] !== [] && $audience->agent) {
            // An erased requester's conversation never shows, even if its
            // vectors could not be purged.
            $excluded = Ticketing::ticketActivityModel()::query()->withoutTenancy()
                ->whereIn('ticket_id', $ids[self::TYPE_TICKET])
                ->whereIn('event', [self::EXCLUDED, 'requester.anonymized'])
                ->pluck('ticket_id')
                ->map(fn (mixed $key): string => (string) $key)
                ->all();

            $allowed[self::TYPE_TICKET] = $this->inTenant(Ticketing::ticketModel()::query()->withoutTenancy(), $tenant)
                ->whereKey(array_values(array_diff($ids[self::TYPE_TICKET], $excluded)))
                ->get()
                ->filter(fn (Ticket $ticket): bool => $this->requests->isClosed($ticket))
                ->map(fn (Ticket $ticket): string => (string) $ticket->getKey())
                ->values()
                ->all();
        }

        return array_values(array_filter($hits, fn (RagHit $hit): bool => in_array($hit->id, $allowed[$hit->type] ?? [], true)
            && ($hit->type !== self::TYPE_ARTICLE || $hit->version === ($versions[$hit->id] ?? null))));
    }

    /**
     * Rows of the tenant — and shared rows, when sharing is on.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function inTenant(Builder $query, int|string|null $tenant): Builder
    {
        $column = $this->tenants->column();

        return $query->where(function ($scoped) use ($column, $tenant): void {
            $tenant === null ? $scoped->whereNull($column) : $scoped->where($column, $tenant);

            if ($tenant !== null && $this->tenants->allowsShared()) {
                $scoped->orWhereNull($column);
            }
        });
    }

    /**
     * Resolved tickets like this question — for the agent who handles it.
     *
     * @return list<RagHit>
     */
    public function similarTickets(string $text, int|string|null $tenant, int $limit = 5, ?float $minScore = null): array
    {
        return $this->search($text, KnowledgeAudience::agent(), $tenant, null, $limit, $minScore, [self::TYPE_TICKET]);
    }

    /**
     * Ingest (or re-ingest) one document and drop its older versions (the
     * caller holds its lock).
     *
     * @param  array<string, string>  $metadata
     */
    protected function put(int|string|null $tenant, string $type, int|string $id, string $title, string $text, array $metadata): void
    {
        $key = $type.':'.$id;

        if (trim($text) === '') {
            $this->purge($tenant, $key);

            return;
        }

        Rag::forTenant($this->ragTenant($tenant), function () use ($key, $type, $id, $title, $text, $metadata): void {
            $document = Rag::ingest(Rag::source()->text($text, [
                'document_key' => $key,
                'title' => $title,
                'rag_vector_metadata' => array_merge($metadata, [
                    'type' => $type,
                    'source_id' => (string) $id,
                    'title' => $title,
                ]),
            ]));

            if ($document->status !== 'indexed') {
                Rag::process($document, ['namespace' => $this->namespace()]);
            }

            // The engine keeps a replaced version searchable: purge it.
            $this->documents($key)->whereKeyNot($document->getKey())->get()
                ->each(fn (Document $old) => Rag::ingestor()->purge($old));
        });
    }

    /**
     * Remove every version of a document (the caller holds its lock).
     */
    protected function purge(int|string|null $tenant, string $key): void
    {
        Rag::forTenant($this->ragTenant($tenant), function () use ($key): void {
            $this->documents($key)->get()->each(fn (Document $document) => Rag::ingestor()->purge($document));
        });
    }

    /**
     * One writer per document at a time: two workers re-indexing the same
     * article must not each purge the other's fresh version. Keyed on the
     * document alone (ids are unique across tenants), so a job that names
     * another tenant — a merged-away shared ticket — still takes the same
     * lock.
     *
     * @param  \Closure(): mixed  $callback
     */
    protected function locked(int|string|null $tenant, string $key, \Closure $callback): void
    {
        Cache::lock('ticketing:rag:'.hash('sha256', $key), 300)->block(60, $callback);
    }

    /**
     * @return Builder<Document>
     */
    protected function documents(string $key): Builder
    {
        return Document::query()
            ->where('tenant_id', Rag::tenant()->id())
            ->where('metadata->document_key', $key);
    }

    protected function articleUrl(KbArticle $article): ?string
    {
        $template = config('ticketing.rag.article_url');

        return is_string($template) && $template !== ''
            ? strtr($template, ['{slug}' => $article->slug, '{locale}' => $article->locale, '{id}' => (string) $article->getKey()])
            : null;
    }

    protected function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', "\n", $html)), ENT_QUOTES | ENT_HTML5));
    }
}
