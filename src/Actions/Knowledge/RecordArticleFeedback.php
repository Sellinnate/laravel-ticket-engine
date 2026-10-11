<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions\Knowledge;

use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Events\ArticleFeedbackRecorded;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\KbArticleFeedback;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;

/**
 * Records a "was this helpful?" vote. Each voter counts once per article: a
 * repeated vote is ignored, a changed vote moves the counters. The counters on
 * the article are adjusted under its row lock, so concurrent votes never drift.
 */
class RecordArticleFeedback
{
    public function handle(KbArticle $article, bool $helpful, string $voterKey, ?string $comment = null, ?Ticket $ticket = null): KbArticleFeedback
    {
        $result = DB::transaction(function () use ($article, $helpful, $voterKey, $comment, $ticket): array {
            $articleModel = Ticketing::kbArticleModel();

            /** @var KbArticle $locked */
            $locked = $articleModel::query()->withoutTenancy()
                ->lockForUpdate()
                ->whereKey($article->getKey())
                ->firstOrFail();

            $feedbackModel = Ticketing::kbArticleFeedbackModel();

            /** @var KbArticleFeedback|null $existing */
            $existing = $feedbackModel::query()->withoutTenancy()
                ->where('article_id', $locked->getKey())
                ->where('voter_key', $voterKey)
                ->first();

            $comment = $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 2000) : null;

            if ($existing === null) {
                /** @var KbArticleFeedback $feedback */
                $feedback = $feedbackModel::query()->create(array_merge($locked->tenantAttributes(), [
                    'article_id' => $locked->getKey(),
                    'helpful' => $helpful,
                    'voter_key' => $voterKey,
                    'comment' => $comment,
                    'ticket_id' => $ticket?->getKey(),
                ]));

                $articleModel::query()->withoutTenancy()->whereKey($locked->getKey())
                    ->increment($helpful ? 'helpful_count' : 'not_helpful_count');

                return [$locked, $feedback, true];
            }

            $changed = $existing->helpful !== $helpful;

            if ($changed) {
                $articleModel::query()->withoutTenancy()->whereKey($locked->getKey())->update([
                    'helpful_count' => DB::raw($helpful ? 'helpful_count + 1' : 'helpful_count - 1'),
                    'not_helpful_count' => DB::raw($helpful ? 'not_helpful_count - 1' : 'not_helpful_count + 1'),
                ]);
            }

            $existing->forceFill(array_filter([
                'helpful' => $helpful,
                'comment' => $comment,
            ], fn (mixed $value): bool => $value !== null))->save();

            return [$locked, $existing, $changed || $existing->wasChanged()];
        });

        /** @var array{0: KbArticle, 1: KbArticleFeedback, 2: bool} $result */
        [$locked, $feedback, $recorded] = $result;

        if ($recorded) {
            ArticleFeedbackRecorded::dispatch($locked->refresh(), $feedback);
        }

        return $feedback;
    }
}
