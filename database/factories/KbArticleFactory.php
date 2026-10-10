<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Selli\Ticketing\Enums\ArticleStatus;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Models\KbArticle;

/**
 * @extends Factory<KbArticle>
 *
 * @internal
 */
class KbArticleFactory extends Factory
{
    protected $model = KbArticle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'type' => ArticleType::Article,
            'slug' => Str::slug($title),
            'slug_key' => hash('sha256', 'factory|'.Str::uuid()),
            'locale' => 'en',
            'title' => $title,
            'body' => fake()->paragraphs(3, true),
            'body_format' => BodyFormat::Markdown,
            'status' => ArticleStatus::Draft,
            'visibility' => KnowledgeVisibility::Public,
            'version' => 1,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ArticleStatus::Published,
            'published_at' => now(),
        ]);
    }
}
