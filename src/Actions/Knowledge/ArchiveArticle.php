<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Enums\ArticleStatus;
use Selli\Ticketing\Events\ArticleArchived;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Support\Ticketing;

/**
 * Withdraws an article from its audience and from search, keeping it (and its
 * versions and ticket links) for history. Re-publish to restore it.
 */
class ArchiveArticle
{
    public function handle(KbArticle $article, ?Model $actor = null): KbArticle
    {
        $archived = DB::transaction(function () use ($article): ?KbArticle {
            /** @var KbArticle $locked */
            $locked = Ticketing::kbArticleModel()::query()->withoutTenancy()
                ->lockForUpdate()
                ->whereKey($article->getKey())
                ->firstOrFail();

            if ($locked->status === ArticleStatus::Archived) {
                return null;
            }

            $locked->forceFill([
                'status' => ArticleStatus::Archived,
                'archived_at' => now(),
            ])->save();

            return $locked;
        });

        if ($archived === null) {
            return $article->refresh();
        }

        ArticleArchived::dispatch($archived, $actor);

        return $archived;
    }
}
