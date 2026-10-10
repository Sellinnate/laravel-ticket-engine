<?php

declare(strict_types=1);

namespace Selli\Ticketing\Data;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Models\KbArticle;

/**
 * Typed input for creating or updating a knowledge-base article. On update, a
 * null field keeps the stored value.
 */
final readonly class ArticleData
{
    /**
     * @param  Model|null  $audience  restrict a public/customer article to one host scope (e.g. a client)
     * @param  KbArticle|null  $translationOf  group the new article with an existing one (another locale)
     * @param  list<string>|null  $keywords
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public ?string $title = null,
        public ?string $body = null,
        public ?ArticleType $type = null,
        public ?string $locale = null,
        public ?string $slug = null,
        public ?string $summary = null,
        public ?array $keywords = null,
        public int|string|null $categoryId = null,
        public ?KnowledgeVisibility $visibility = null,
        public ?Model $audience = null,
        public ?BodyFormat $bodyFormat = null,
        public ?KbArticle $translationOf = null,
        public ?array $meta = null,
    ) {}
}
