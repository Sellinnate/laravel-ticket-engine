<?php

declare(strict_types=1);

namespace Selli\Ticketing\Knowledge;

use Selli\Ticketing\Enums\ArticleType;

/**
 * A knowledge-base search: the text, who is asking, and optional narrowing.
 */
final readonly class KnowledgeQuery
{
    public function __construct(
        public string $text,
        public KnowledgeAudience $audience,
        public ?string $locale = null,
        public int $limit = 10,
        public ?ArticleType $type = null,
        public int|string|null $categoryId = null,
    ) {}
}
