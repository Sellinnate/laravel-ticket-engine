<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Models\KbArticleLink;
use Selli\Ticketing\Models\Ticket;

/**
 * A knowledge-base article was linked to a ticket ("resolved with",
 * "suggested", "linked").
 */
class ArticleLinkedToTicket implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public KbArticleLink $link,
        public ?Model $actor = null,
    ) {}
}
