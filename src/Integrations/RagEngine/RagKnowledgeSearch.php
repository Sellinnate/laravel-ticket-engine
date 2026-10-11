<?php

declare(strict_types=1);

namespace Selli\Ticketing\Integrations\RagEngine;

use Selli\Ticketing\Actions\Knowledge\SaveArticle;
use Selli\Ticketing\Contracts\KnowledgeSearch;
use Selli\Ticketing\Knowledge\KnowledgeQuery;
use Selli\Ticketing\Knowledge\KnowledgeResult;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Semantic knowledge-base search through the RAG index (bind it with
 * `ticketing.rag.knowledge_search`). Every hit is re-read from the database
 * through the article audience scope, so a stale index entry (an article
 * unpublished or restricted meanwhile) is never returned.
 */
class RagKnowledgeSearch implements KnowledgeSearch
{
    public function __construct(
        protected RagKnowledge $rag,
        protected TenantContext $tenants,
    ) {}

    public function search(KnowledgeQuery $query): array
    {
        $hits = $this->rag->search(
            $query->text,
            $query->audience,
            $this->tenants->current(),
            $query->locale,
            $query->limit,
            null,
            [RagKnowledge::TYPE_ARTICLE],
            $query->type,
            $query->categoryId,
        );

        if ($hits === []) {
            return [];
        }

        // The query's own filters too: a category, type or locale changed
        // since the article was indexed must not satisfy them.
        $model = Ticketing::kbArticleModel();
        $articles = $model::query()
            ->visibleTo($query->audience)
            ->whereKey(array_map(fn (RagHit $hit): string => $hit->id, $hits))
            ->when($query->type !== null, fn ($articles) => $articles->where('type', $query->type?->value))
            ->when($query->categoryId !== null, fn ($articles) => $articles->where('category_id', $query->categoryId))
            ->when($query->locale !== null && $query->locale !== '', fn ($articles) => $articles->whereIn('locale', array_values(array_unique([(string) $query->locale, SaveArticle::defaultLocale()]))))
            ->get()
            ->keyBy(fn (KbArticle $article): string => (string) $article->getKey());

        $results = [];
        foreach ($hits as $hit) {
            $article = $articles->get($hit->id);

            if ($article instanceof KbArticle) {
                $results[] = new KnowledgeResult($article, $hit->score, $hit->excerpt);
            }
        }

        return $results;
    }
}
