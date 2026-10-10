<?php

declare(strict_types=1);

namespace Selli\Ticketing\Knowledge;

use Selli\Ticketing\Models\KbArticle;

/**
 * One search hit: the article, a relevance score normalised to 0..1 and a short
 * excerpt around the match.
 */
final readonly class KnowledgeResult
{
    public function __construct(
        public KbArticle $article,
        public float $score,
        public string $excerpt,
    ) {}

    /**
     * @return array{id: int|string, slug: string, locale: string, type: string, title: string, excerpt: string, score: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->article->getKey(),
            'slug' => $this->article->slug,
            'locale' => $this->article->locale,
            'type' => $this->article->type->value,
            'title' => $this->article->title,
            'excerpt' => $this->excerpt,
            'score' => round($this->score, 4),
        ];
    }
}
