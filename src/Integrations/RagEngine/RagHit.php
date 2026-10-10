<?php

declare(strict_types=1);

namespace Selli\Ticketing\Integrations\RagEngine;

/**
 * One document found in the RAG index — its best matching passage.
 */
final readonly class RagHit
{
    public function __construct(
        public string $type,
        public string $id,
        public string $title,
        public float $score,
        public string $excerpt,
        public ?string $url = null,
        public ?string $locale = null,
        public ?string $version = null,
    ) {}
}
