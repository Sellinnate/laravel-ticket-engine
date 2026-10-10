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
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Gdpr\RequesterTickets;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\CannedResponse;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Retrieval\SearchBuilder;
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

    public const TYPE_CANNED = 'canned_response';

    /** The audit event that keeps a ticket out of the index for good. */
    public const EXCLUDED = 'rag.excluded';

    public function __construct(
        protected TenantContext $tenants,
        protected AiRequestFactory $requests,
        protected PiiScrubber $pii = new PiiScrubber,
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

        // Knowledge, not a record: the customer's question and the final
        // answer — never an internal note — with the personal data taken out.
        [$question, $answer] = $this->questionAndAnswer($ticket);

        if ($question === null || $answer === null) {
            $this->purge($tenant, self::TYPE_TICKET.':'.$ticket->getKey());

            return;
        }

        $names = $this->peopleOn($ticket);
        $text = $this->pii->scrub($ticket->title."\n\nQuestion: ".$question."\n\nAnswer: ".$answer, $names);

        $this->put($tenant, self::TYPE_TICKET, $ticket->getKey(), $ticket->reference.' '.$this->pii->scrub($ticket->title, $names), $text, [
            'visibility' => KnowledgeVisibility::Internal->value,
            'audience' => '*',
            'locale' => $ticket->language ?? SaveArticle::defaultLocale(),
            'reference' => $ticket->reference,
            // Checked at search time, like an article's version.
            'version' => $this->revisions([$ticket->getKey()])[(string) $ticket->getKey()] ?? '0',
        ]);
    }

    /**
     * The first message of the customer and the last public answer of an
     * agent or the AI.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function questionAndAnswer(Ticket $ticket): array
    {
        $model = Ticketing::ticketMessageModel();
        $key = (new $model)->getKeyName();
        $public = fn () => $ticket->messages()->withoutTenancy()->with('author')->where('visibility', MessageVisibility::Public->value);

        // The requester's own messages are never the answer, even when their
        // model can also act on tickets.
        $requesters = [];
        foreach ($ticket->participants()->withoutTenancy()->where('role', ParticipantRole::Requester->value)->get(['participant_type', 'participant_id']) as $participant) {
            $requesters[$participant->participant_type.':'.$participant->participant_id] = true;
        }

        $isAnswer = fn (TicketMessage $message): bool => $message->author_id === null
            ? $message->source === MessageSource::Ai
            : ! isset($requesters[$message->author_type.':'.$message->author_id]) && $message->author instanceof CanActOnTickets;

        // Scanned lazily, in batches, until found: however long the ticket.
        $question = $public()->lazyById(50, $key)->first(fn (TicketMessage $message): bool => ! $isAnswer($message));
        $answer = $public()->lazyByIdDesc(50, $key)->first($isAnswer);

        $text = fn (?TicketMessage $message): ?string => $message === null ? null
            : trim($message->body_format === BodyFormat::Html ? $this->plain($message->body) : $message->body);

        return [$text($question), $text($answer)];
    }

    /**
     * The names of the requesters and contacts on a ticket.
     *
     * @return list<string>
     */
    protected function peopleOn(Ticket $ticket): array
    {
        $names = [];

        // By role on the ticket — requesters, Cc'd people, watchers — never
        // the assignee or collaborators (the agents answering).
        $roles = [ParticipantRole::Requester->value, ParticipantRole::Cc->value, ParticipantRole::Watcher->value];

        foreach ($ticket->participants()->withoutTenancy()->with('participant')->whereIn('role', $roles)->get() as $participant) {
            $person = $participant->participant;
            $name = $person instanceof Model ? $person->getAttribute('name') : null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;

                foreach (preg_split('/\s+/', $name) ?: [] as $part) {
                    $names[] = $part;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Index an active canned response as internal knowledge for agents (an
     * inactive or deleted one is removed), read under the document's lock.
     */
    public function syncCannedResponse(int|string $id, int|string|null $tenant): void
    {
        $key = self::TYPE_CANNED.':'.$id;

        $this->locked($tenant, $key, function () use ($id, $tenant, $key): void {
            $canned = Ticketing::cannedResponseModel()::query()->withoutTenancy()->find($id);

            if (! $canned instanceof CannedResponse || ! $canned->is_active) {
                $this->purge($tenant, $key);

                return;
            }

            $this->put($canned->getAttribute($canned->getTenantColumn()), self::TYPE_CANNED, $canned->getKey(), $canned->name, $canned->name."\n\n".$canned->body, [
                'visibility' => KnowledgeVisibility::Internal->value,
                'audience' => '*',
                'locale' => SaveArticle::defaultLocale(),
                'version' => $this->cannedVersion($canned),
            ]);
        });
    }

    protected function cannedVersion(CannedResponse $canned): string
    {
        return hash('xxh128', $canned->name."\0".$canned->body);
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
     * Keep one ticket out of the index for good (an audited exclusion) and
     * purge it — a purge that fails is retried on the queue.
     */
    public function exclude(int|string $ticketId, int|string|null $tenant, string $reason): void
    {
        $ticket = Ticketing::ticketModel()::query()->withoutTenancy()->find($ticketId);

        if ($ticket instanceof Ticket && ! $this->excluded($ticket)) {
            app(AuditLogger::class)->record(ticket: $ticket, event: self::EXCLUDED, context: ['reason' => $reason]);
        }

        try {
            $this->syncTicket($ticketId, $tenant);
        } catch (\Throwable $exception) {
            report($exception);
            RagSyncJob::dispatch(self::TYPE_TICKET, $ticketId, $tenant);
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

        $hybrid = config('ticketing.rag.hybrid', true) !== false;
        $best = [];
        $ranks = [];

        foreach ($ragTenants as $ragTenant) {
            /** @var array{0: list<SearchHit>, 1: array<string, int>} $found */
            $found = Rag::forTenant($ragTenant, function () use ($text, $audience, $locale, $limit, $minScore, $types, $articleType, $categoryId, $hybrid): array {
                $base = fn () => $this->filtered(Rag::search($text)->namespace($this->namespace())->topK($limit * 3), $audience, $types, $locale, $articleType, $categoryId);

                // Relevance is decided on the vector score (a threshold means
                // something there); the order, when hybrid, also weighs the
                // exact words (BM25, fused by reciprocal rank).
                $relevant = $base()->threshold($minScore)->get();

                if (! $hybrid || $relevant === []) {
                    return [$relevant, []];
                }

                $positions = [];
                foreach ($base()->hybrid()->fetch($limit * 6)->get() as $position => $hit) {
                    $positions[$hit->chunkId ?? $hit->id] ??= $position;
                }

                return [$relevant, $positions];
            });

            [$hits, $positions] = $found;

            foreach ($hits as $hit) {
                $type = $hit->metadata['type'] ?? null;
                $id = $hit->metadata['source_id'] ?? null;

                if (is_string($type) && is_string($id)) {
                    $ranks[$type.':'.$id] = min($ranks[$type.':'.$id] ?? PHP_INT_MAX, $positions[$hit->chunkId ?? $hit->id] ?? PHP_INT_MAX);
                }
            }

            $this->collect($best, $hits);
        }

        usort($best, fn (RagHit $a, RagHit $b): int => $hybrid
            ? [$ranks[$a->type.':'.$a->id] ?? PHP_INT_MAX, $b->score] <=> [$ranks[$b->type.':'.$b->id] ?? PHP_INT_MAX, $a->score]
            : $b->score <=> $a->score);

        return array_slice($this->verified($best, $audience, $tenant, $locale, $articleType, $categoryId), 0, $limit);
    }

    /**
     * The audience, type and article filters — positive conditions only:
     * unknown or missing metadata never matches.
     *
     * @param  list<string>  $types
     */
    protected function filtered(SearchBuilder $search, KnowledgeAudience $audience, array $types, ?string $locale, ?ArticleType $articleType, int|string|null $categoryId): SearchBuilder
    {
        $search->where('type', ['in' => $types])
            ->where('visibility', ['in' => array_map(fn (KnowledgeVisibility $visibility): string => $visibility->value, $audience->visibilities())]);

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

        return $search;
    }

    /**
     * Keep the best passage of each document.
     *
     * @param  array<string, RagHit>  $best
     * @param  list<SearchHit>  $hits
     */
    protected function collect(array &$best, array $hits): void
    {
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

    /**
     * Only what the database still allows: the index follows the help desk
     * asynchronously, so an article unpublished, restricted or moved — or a
     * ticket erased — since it was indexed is dropped here, before anyone
     * (a customer, a model's prompt) sees its text.
     *
     * @param  list<RagHit>  $hits
     * @return list<RagHit>
     */
    protected function verified(
        array $hits,
        KnowledgeAudience $audience,
        int|string|null $tenant,
        ?string $locale = null,
        ?ArticleType $articleType = null,
        int|string|null $categoryId = null,
    ): array {
        $ids = [self::TYPE_ARTICLE => [], self::TYPE_TICKET => [], self::TYPE_CANNED => []];

        foreach ($hits as $hit) {
            if (isset($ids[$hit->type])) {
                $ids[$hit->type][] = $hit->id;
            }
        }

        $allowed = [self::TYPE_ARTICLE => [], self::TYPE_TICKET => [], self::TYPE_CANNED => []];

        // Articles: readable by this audience now, and the indexed version is
        // the current one (an edit not synced yet must not leak the old text).
        $versions = [];

        if ($ids[self::TYPE_ARTICLE] !== []) {
            $model = Ticketing::kbArticleModel();
            // The search's own filters on the current rows: a type, category
            // or locale changed since indexing (no new version) must not pass.
            $articles = $this->inTenant($model::query()->withoutTenancy()->visibleTo($audience), $tenant)
                ->whereKey($ids[self::TYPE_ARTICLE])
                ->when($articleType !== null, fn ($query) => $query->where('type', $articleType?->value))
                ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
                ->when($locale !== null && $locale !== '', fn ($query) => $query->whereIn('locale', array_values(array_unique([(string) $locale, SaveArticle::defaultLocale()]))))
                ->get([(new $model)->getKeyName(), 'version']);

            foreach ($articles as $article) {
                $versions[self::TYPE_ARTICLE.':'.$article->getKey()] = (string) $article->version;
                $allowed[self::TYPE_ARTICLE][] = (string) $article->getKey();
            }
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

            // The conversation as indexed must still be the current one (a
            // split, an edit, a late reply not synced yet).
            $versions += $this->revisions(array_values($allowed[self::TYPE_TICKET]));
        }

        // Canned responses: agents only, still active, as indexed.
        if ($ids[self::TYPE_CANNED] !== [] && $audience->agent) {
            foreach ($this->inTenant(Ticketing::cannedResponseModel()::query()->withoutTenancy(), $tenant)->whereKey($ids[self::TYPE_CANNED])->where('is_active', true)->get() as $canned) {
                /** @var CannedResponse $canned */
                $allowed[self::TYPE_CANNED][] = (string) $canned->getKey();
                $versions[self::TYPE_CANNED.':'.$canned->getKey()] = $this->cannedVersion($canned);
            }
        }

        return array_values(array_filter($hits, fn (RagHit $hit): bool => in_array($hit->id, $allowed[$hit->type] ?? [], true)
            && $hit->version === ($versions[$hit->type.':'.$hit->id] ?? null)));
    }

    /**
     * A revision of each ticket's public conversation — how many messages,
     * the first and the last — that changes when messages are added, split
     * off or merged in (messages are never edited; a metadata-only update,
     * such as retention scrubbing an email sender, leaves it alone).
     *
     * @param  list<int|string>  $ticketIds
     * @return array<string, string> "ticket:<id>" and "<id>" keys
     */
    protected function revisions(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        $model = Ticketing::ticketMessageModel();
        $rows = $model::query()->withoutTenancy()
            ->whereIn('ticket_id', $ticketIds)
            ->where('visibility', MessageVisibility::Public->value)
            ->toBase()
            ->select('ticket_id')
            ->selectRaw('count(*) as messages')
            ->selectRaw('min(id) as first_id')
            ->selectRaw('max(id) as last_id')
            ->groupBy('ticket_id')
            ->get();

        $revisions = [];
        foreach ($ticketIds as $id) {
            $revisions[(string) $id] = '0';
        }

        foreach ($rows as $row) {
            $revisions[(string) $row->ticket_id] = hash('xxh128', $row->messages.'|'.$row->first_id.'|'.$row->last_id);
        }

        $keyed = [];
        foreach ($revisions as $id => $revision) {
            $keyed[(string) $id] = $revision;
            $keyed[self::TYPE_TICKET.':'.$id] = $revision;
        }

        return $keyed;
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
        // The model's own tenant column (a host model may rename it).
        $model = $query->getModel();
        $column = method_exists($model, 'getQualifiedTenantColumn') ? $model->getQualifiedTenantColumn() : $this->tenants->column();

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
