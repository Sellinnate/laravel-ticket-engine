<?php

declare(strict_types=1);

namespace Selli\Ticketing\Knowledge;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Actions\Knowledge\ArchiveArticle;
use Selli\Ticketing\Actions\Knowledge\LinkArticleToTicket;
use Selli\Ticketing\Actions\Knowledge\PublishArticle;
use Selli\Ticketing\Actions\Knowledge\RecordArticleFeedback;
use Selli\Ticketing\Actions\Knowledge\SaveArticle;
use Selli\Ticketing\Contracts\KnowledgeSearch;
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\ArticleLinkRelation;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\KbArticleFeedback;
use Selli\Ticketing\Models\KbArticleLink;
use Selli\Ticketing\Models\KbCategory;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;

/**
 * The headless help center: search, read, write, publish, vote and link
 * articles to tickets. `Ticketing::knowledge()` returns it.
 *
 * Reads always go through an audience (public visitor, identified customer,
 * agent), so a caller can never see an article it is not meant to.
 */
class KnowledgeBase
{
    public function __construct(protected Container $container) {}

    /**
     * Best articles for a question, for one audience.
     *
     * @return list<KnowledgeResult>
     */
    public function search(
        string $text,
        KnowledgeAudience $audience,
        ?string $locale = null,
        int $limit = 10,
        ?ArticleType $type = null,
        int|string|null $categoryId = null,
    ): array {
        return $this->container->make(KnowledgeSearch::class)->search(
            new KnowledgeQuery($text, $audience, $locale, min(50, $limit), $type, $categoryId),
        );
    }

    /**
     * Deflection: up to $limit articles worth showing before a ticket is opened.
     *
     * @return list<KnowledgeResult>
     */
    public function suggest(string $text, KnowledgeAudience $audience, ?string $locale = null, int $limit = 3): array
    {
        return $this->search($text, $audience, $locale, $limit);
    }

    /**
     * A published article by slug, in the requested locale or — failing that —
     * the default locale, if the audience may read it.
     */
    public function find(string $slug, KnowledgeAudience $audience, ?string $locale = null): ?KbArticle
    {
        $model = Ticketing::kbArticleModel();
        $locales = array_values(array_unique(array_filter([$locale, SaveArticle::defaultLocale()])));

        foreach ($locales as $candidate) {
            $article = $model::query()->visibleTo($audience)->where('slug', $slug)->where('locale', $candidate)->first();

            if ($article instanceof KbArticle) {
                return $article;
            }
        }

        return null;
    }

    /**
     * The article in another locale (same translation group), if published and
     * visible to the audience.
     */
    public function translation(KbArticle $article, string $locale, KnowledgeAudience $audience): ?KbArticle
    {
        if ($article->locale === $locale) {
            return $article->isVisibleTo($audience) ? $article : null;
        }

        if ($article->translation_group === null) {
            return null;
        }

        $translation = Ticketing::kbArticleModel()::query()->visibleTo($audience)
            ->where('translation_group', $article->translation_group)
            ->where('locale', $locale)
            ->first();

        return $translation instanceof KbArticle ? $translation : null;
    }

    /**
     * Published articles of a category (or all), newest first.
     *
     * @return Collection<int, KbArticle>
     */
    public function articles(KnowledgeAudience $audience, ?string $locale = null, int|string|null $categoryId = null, ?ArticleType $type = null, int $limit = 50): Collection
    {
        return Ticketing::kbArticleModel()::query()->visibleTo($audience)
            ->when($locale !== null, fn ($query) => $query->where('locale', $locale))
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->when($type !== null, fn ($query) => $query->where('type', $type?->value))
            ->orderByDesc('published_at')
            ->limit(max(1, min(200, $limit)))
            ->get();
    }

    /**
     * Active categories in display order.
     *
     * @return Collection<int, KbCategory>
     */
    public function categories(): Collection
    {
        return Ticketing::kbCategoryModel()::query()->active()->orderBy('position')->orderBy('name')->get();
    }

    /**
     * Create (when $article is null) or update an article. New articles start
     * as drafts; see publish().
     */
    public function save(ArticleData $data, ?KbArticle $article = null, ?Model $author = null): KbArticle
    {
        return $this->container->make(SaveArticle::class)->handle($data, $article, $author);
    }

    public function publish(KbArticle $article, ?Model $actor = null): KbArticle
    {
        return $this->container->make(PublishArticle::class)->handle($article, $actor);
    }

    public function archive(KbArticle $article, ?Model $actor = null): KbArticle
    {
        return $this->container->make(ArchiveArticle::class)->handle($article, $actor);
    }

    /**
     * Record a "was this helpful?" vote. The voter is a host model (or contact),
     * or an opaque string for anonymous readers (e.g. a widget conversation id).
     */
    public function feedback(KbArticle $article, bool $helpful, Model|string $voter, ?string $comment = null, ?Ticket $ticket = null): KbArticleFeedback
    {
        return $this->container->make(RecordArticleFeedback::class)->handle($article, $helpful, self::voterKey($voter), $comment, $ticket);
    }

    /**
     * Link an article to a ticket ("resolved with article X").
     */
    public function link(KbArticle $article, Ticket $ticket, ArticleLinkRelation $relation = ArticleLinkRelation::Linked, ?Model $actor = null): KbArticleLink
    {
        return $this->container->make(LinkArticleToTicket::class)->handle($article, $ticket, $relation, $actor);
    }

    /**
     * The articles linked to a ticket, with their link relation.
     *
     * @return Collection<int, KbArticleLink>
     */
    public function linksFor(Ticket $ticket): Collection
    {
        return Ticketing::kbArticleLinkModel()::query()->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->with(['article' => fn ($query) => $query->withoutTenancy()])
            ->get();
    }

    /**
     * A stable, non-reversible key for a voter.
     */
    public static function voterKey(Model|string $voter): string
    {
        return $voter instanceof Model
            ? hash('sha256', 'model|'.$voter->getMorphClass().'|'.$voter->getKey())
            : hash('sha256', 'anonymous|'.$voter);
    }
}
