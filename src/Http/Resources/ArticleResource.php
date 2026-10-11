<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Selli\Ticketing\Models\KbArticle;

/**
 * @mixin KbArticle
 *
 * @internal
 */
class ArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'slug' => $this->slug,
            'locale' => $this->locale,
            'translation_group' => $this->translation_group,
            'category_id' => $this->category_id,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $this->body,
            'body_format' => $this->body_format->value,
            'keywords' => $this->keywords,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'audience' => $this->audience_type !== null ? ['type' => $this->audience_type, 'id' => $this->audience_id] : null,
            'version' => $this->version,
            'helpful_count' => $this->helpful_count,
            'not_helpful_count' => $this->not_helpful_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
