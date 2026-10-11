<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

/**
 * A piece of knowledge an AI answer relied on, stored with the reply so agents
 * and customers can check it ("kb_article", "faq", "canned_response",
 * "ticket", or a driver-specific type).
 */
final readonly class AiSource
{
    public function __construct(
        public string $type,
        public string $id,
        public string $title,
        public ?float $score = null,
        public ?string $url = null,
        public ?string $excerpt = null,
    ) {}

    /**
     * @return array{type: string, id: string, title: string, score: float|null, url: string|null, excerpt: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'score' => $this->score !== null ? round($this->score, 4) : null,
            'url' => $this->url,
            'excerpt' => $this->excerpt,
        ];
    }
}
