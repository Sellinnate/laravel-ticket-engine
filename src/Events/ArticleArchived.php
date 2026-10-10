<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Models\KbArticle;

/**
 * An article was withdrawn from its audience.
 */
class ArticleArchived implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public KbArticle $article,
        public ?Model $actor = null,
    ) {}
}
