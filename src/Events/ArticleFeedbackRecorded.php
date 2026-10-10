<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\KbArticleFeedback;

/**
 * A reader voted an article helpful or not (a changed vote fires again).
 */
class ArticleFeedbackRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public KbArticle $article,
        public KbArticleFeedback $feedback,
    ) {}
}
