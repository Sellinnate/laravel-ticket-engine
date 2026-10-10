<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\ArticleStatus;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Events\ArticleSaved;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\KbArticleVersion;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Creates an article (as a draft) or updates one. Every content change bumps
 * the version and snapshots an immutable KbArticleVersion, so the history of
 * what a customer was told is never lost. Slugs are unique per tenant and
 * locale; a clash gets a numeric suffix.
 */
class SaveArticle
{
    /** Columns whose change creates a new version. */
    protected const CONTENT = ['title', 'summary', 'body', 'body_format', 'keywords'];

    public function __construct(protected TenantContext $tenant) {}

    public function handle(ArticleData $data, ?KbArticle $article = null, ?Model $author = null): KbArticle
    {
        $saved = DB::transaction(function () use ($data, $article, $author): KbArticle {
            return $article === null
                ? $this->create($data, $author)
                : $this->update($this->lock($article), $data, $author);
        });

        if ($saved->wasRecentlyCreated || $saved->wasChanged()) {
            ArticleSaved::dispatch($saved, $author);
        }

        return $saved;
    }

    protected function create(ArticleData $data, ?Model $author): KbArticle
    {
        if ($data->title === null || trim($data->title) === '' || $data->body === null) {
            throw new InvalidArgumentException('A new article needs a title and a body.');
        }

        $locale = $data->locale ?? self::defaultLocale();
        $model = Ticketing::kbArticleModel();
        $this->assertCategory($data->categoryId);

        // A translation lives in the same tenant as the article it translates.
        if ($data->translationOf !== null && ! $this->inCurrentTenant($data->translationOf)) {
            throw CrossTenantException::forAssignment('article');
        }

        $tenant = $this->tenant->current();
        $slug = $this->uniqueSlug($data->slug ?? $data->title, $locale, null, $tenant);

        /** @var KbArticle $article */
        $article = $this->withSlugRetry($data->slug ?? $data->title, $locale, null, $tenant, $slug, fn (string $slug): KbArticle => $model::query()->create(array_merge([
            'type' => $data->type ?? ArticleType::Article,
            'locale' => $locale,
            'translation_group' => $data->translationOf !== null
                ? $this->translationGroup($data->translationOf)
                : (string) Str::uuid(),
            'title' => trim($data->title),
            'summary' => $data->summary,
            'body' => $data->body,
            'body_format' => $data->bodyFormat ?? BodyFormat::Markdown,
            'keywords' => self::keywords($data->keywords),
            'category_id' => $data->categoryId,
            'status' => ArticleStatus::Draft,
            'visibility' => $data->visibility ?? KnowledgeVisibility::Public,
            'version' => 1,
            'meta' => $data->meta,
            'slug' => $slug,
            'slug_key' => self::slugKey($tenant, $locale, $slug),
        ], $this->morph('audience', $data->audience), $this->morph('author', $author))));

        $this->snapshot($article, $author);

        return $article;
    }

    protected function update(KbArticle $article, ArticleData $data, ?Model $author): KbArticle
    {
        $this->assertCategory($data->categoryId, $article);

        $changes = array_filter([
            'type' => $data->type,
            'title' => $data->title !== null ? trim($data->title) : null,
            'summary' => $data->summary,
            'body' => $data->body,
            'body_format' => $data->bodyFormat,
            'category_id' => $data->categoryId,
            'visibility' => $data->visibility,
            'meta' => $data->meta,
        ], fn (mixed $value): bool => $value !== null);

        // An explicit empty list clears the keywords (null means "unchanged").
        if ($data->keywords !== null) {
            $changes['keywords'] = self::keywords($data->keywords);
        }

        if ($data->audience !== null) {
            $changes = array_merge($changes, $this->morph('audience', $data->audience));
        }

        // A new locale (fixing a wrong one) re-checks the slug in that locale.
        $locale = $data->locale !== null && $data->locale !== $article->locale ? $data->locale : $article->locale;

        if ($locale !== $article->locale) {
            $changes['locale'] = $locale;
        }

        if (($data->slug !== null && Str::slug($data->slug) !== $article->slug) || $locale !== $article->locale) {
            $tenant = $article->getAttribute($article->getTenantColumn());
            $changes['slug'] = $this->uniqueSlug($data->slug ?? $article->slug, $locale, $article, $tenant);
            $changes['slug_key'] = self::slugKey($tenant, $locale, $changes['slug']);
        }

        $article->fill($changes);

        $contentChanged = $article->isDirty(self::CONTENT);

        if ($contentChanged) {
            $article->version = $article->version + 1;
            $article->fill($this->morph('author', $author));
        }

        $article->save();

        if ($contentChanged) {
            $this->snapshot($article, $author);
        }

        return $article;
    }

    protected function snapshot(KbArticle $article, ?Model $author): void
    {
        $model = Ticketing::kbArticleVersionModel();

        $model::query()->create(array_merge($article->tenantAttributes(), [
            'article_id' => $article->getKey(),
            'version' => $article->version,
            'title' => $article->title,
            'summary' => $article->summary,
            'body' => $article->body,
            'body_format' => $article->body_format,
            'keywords' => $article->keywords,
            'created_at' => now(),
        ], $this->morph('author', $author)));
    }

    protected function lock(KbArticle $article): KbArticle
    {
        /** @var KbArticle $locked */
        $locked = Ticketing::kbArticleModel()::query()->withoutTenancy()
            ->lockForUpdate()
            ->whereKey($article->getKey())
            ->firstOrFail();

        return $locked;
    }

    /**
     * A slug unique within the tenant and locale: "reset-password",
     * "reset-password-2", … (the unique index is the final arbiter).
     */
    /**
     * @param  bool  $currentRead  read the latest committed rows (a locking
     *                             read): a retry inside a REPEATABLE READ
     *                             transaction (InnoDB) would otherwise see the
     *                             snapshot without the slug that just collided
     */
    protected function uniqueSlug(string $source, string $locale, ?KbArticle $ignore, int|string|null $tenant, bool $currentRead = false): string
    {
        $base = Str::slug($source);
        $base = $base !== '' ? mb_substr($base, 0, 180) : 'article';
        $model = Ticketing::kbArticleModel();

        for ($i = 1; $i <= 50; $i++) {
            $slug = $i === 1 ? $base : $base.'-'.$i;

            // Checked in the article's own tenant, unscoped: an update may run in
            // another ambient context than the one the article belongs to.
            $query = $model::query()->withoutTenancy()->withTrashed()
                ->where('slug', $slug)
                ->where('locale', $locale)
                ->where($this->tenant->column(), $tenant)
                ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->getKey()));

            $exists = $currentRead
                ? $query->sharedLock()->limit(1)->value((new $model)->getKeyName()) !== null
                : $query->exists();

            if (! $exists) {
                return $slug;
            }
        }

        throw new \RuntimeException("No free slug for [{$base}] in locale [{$locale}].");
    }

    /**
     * A category must exist in the article's tenant (or be shared, when
     * sharing is on) — for a new article, the current tenant's.
     */
    protected function assertCategory(int|string|null $categoryId, ?KbArticle $article = null): void
    {
        if ($categoryId === null) {
            return;
        }

        $column = $this->tenant->column();
        $tenant = $article !== null ? $article->getAttribute($article->getTenantColumn()) : $this->tenant->current();

        $exists = Ticketing::kbCategoryModel()::query()->withoutTenancy()
            ->whereKey($categoryId)
            ->when($this->tenant->enabled(), fn ($query) => $query->where(function ($scoped) use ($column, $tenant): void {
                $scoped->where($column, $tenant);

                if ($this->tenant->allowsShared()) {
                    $scoped->orWhereNull($column);
                }
            }))
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException("Knowledge-base category [{$categoryId}] does not exist.");
        }
    }

    /**
     * Insert with the allocated slug; if a concurrent save took it between the
     * check and the insert (the unique slug_key refuses it), allocate again.
     * Each attempt runs in a savepoint so the outer transaction survives.
     *
     * @param  \Closure(string): KbArticle  $create
     */
    protected function withSlugRetry(string $source, string $locale, ?KbArticle $ignore, int|string|null $tenant, string $slug, \Closure $create): KbArticle
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): KbArticle => $create($slug));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 3) {
                    throw $exception;
                }

                $slug = $this->uniqueSlug($source, $locale, $ignore, $tenant, currentRead: true);
            }
        }
    }

    /**
     * The non-null uniqueness key of a slug within a tenant and locale.
     */
    public static function slugKey(int|string|null $tenant, string $locale, string $slug): string
    {
        return hash('sha256', ($tenant === null ? 'shared' : 'tenant:'.$tenant).'|'.$locale.'|'.$slug);
    }

    protected function inCurrentTenant(KbArticle $article): bool
    {
        if (! $this->tenant->enabled()) {
            return true;
        }

        $owner = $article->getAttribute($this->tenant->column());
        $current = $this->tenant->current();

        return $owner === null ? $current === null : (string) $owner === (string) $current;
    }

    protected function translationGroup(KbArticle $source): string
    {
        if ($source->translation_group !== null) {
            return $source->translation_group;
        }

        $group = (string) Str::uuid();
        $source->forceFill(['translation_group' => $group])->save();

        return $group;
    }

    /**
     * @return array<string, int|string|null>
     */
    protected function morph(string $name, ?Model $model): array
    {
        if ($model === null) {
            return [];
        }

        return [$name.'_type' => $model->getMorphClass(), $name.'_id' => $model->getKey()];
    }

    /**
     * @param  list<string>|null  $keywords
     */
    protected static function keywords(?array $keywords): ?string
    {
        if ($keywords === null) {
            return null;
        }

        $clean = array_values(array_unique(array_filter(array_map(
            fn (string $keyword): string => trim(mb_strtolower($keyword)),
            $keywords,
        ), fn (string $keyword): bool => $keyword !== '')));

        return $clean === [] ? null : mb_substr(implode(', ', $clean), 0, 1000);
    }

    public static function defaultLocale(): string
    {
        $locale = config('ticketing.knowledge.default_locale') ?? config('app.locale', 'en');

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }
}
