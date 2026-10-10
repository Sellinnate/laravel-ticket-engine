<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Enums\ArticleStatus;
use Selli\Ticketing\Events\ArticlePublished;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Support\Ticketing;

/**
 * Makes an article visible to its audience (and searchable). Publishing an
 * already-published article is a no-op.
 */
class PublishArticle
{
    public function handle(KbArticle $article, ?Model $actor = null): KbArticle
    {
        $published = DB::transaction(function () use ($article): ?KbArticle {
            /** @var KbArticle $locked */
            $locked = Ticketing::kbArticleModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->whereKey($article->getKey())
                ->firstOrFail();

            if ($locked->status === ArticleStatus::Published) {
                return null;
            }

            $locked->forceFill([
                'status' => ArticleStatus::Published,
                'published_at' => $locked->published_at ?? now(),
                'archived_at' => null,
            ])->save();

            return $locked;
        });

        if ($published === null) {
            return $article->refresh();
        }

        ArticlePublished::dispatch($published, $actor);

        return $published;
    }
}
