<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests\Fixtures;

use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\Usage;

/**
 * A deterministic bag-of-words embedder for the bridge tests: texts sharing
 * words are close (cosine of their word counts), unrelated texts are not —
 * unlike the engine's hash-based fake, whose vectors carry no meaning.
 */
final class WordEmbedder implements Embedder
{
    private const DIMENSIONS = 512;

    public function embed(array $texts): EmbeddingResponse
    {
        return new EmbeddingResponse(
            vectors: array_values(array_map(fn (string $text): array => $this->vector($text), $texts)),
            model: 'words',
            dimensions: self::DIMENSIONS,
            usage: new Usage,
        );
    }

    public function embedOne(string $text): EmbeddingResponse
    {
        return $this->embed([$text]);
    }

    public function dimensions(): int
    {
        return self::DIMENSIONS;
    }

    public function model(): string
    {
        return 'words';
    }

    /**
     * @return list<float>
     */
    private function vector(string $text): array
    {
        $vector = array_fill(0, self::DIMENSIONS, 0.0);

        preg_match_all('/\p{L}{4,}/u', mb_strtolower($text), $words);

        foreach ($words[0] as $word) {
            $vector[crc32($word) % self::DIMENSIONS] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector)));

        return $norm > 0 ? array_map(fn (float $v): float => $v / $norm, $vector) : array_fill(0, self::DIMENSIONS, 1 / sqrt(self::DIMENSIONS));
    }
}
