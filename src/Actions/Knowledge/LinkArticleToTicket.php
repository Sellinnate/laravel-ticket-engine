<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Enums\ArticleLinkRelation;
use Selli\Ticketing\Events\ArticleLinkedToTicket;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\KbArticleLink;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantGuard;

/**
 * Links an article to a ticket ("resolved with article X"). The article must
 * belong to the ticket's tenant (or be shared). Linking twice with the same
 * relation is a no-op; the link is audited on the ticket.
 */
class LinkArticleToTicket
{
    public function __construct(
        protected TenantGuard $tenants,
        protected AuditLogger $audit,
    ) {}

    public function handle(KbArticle $article, Ticket $ticket, ArticleLinkRelation $relation = ArticleLinkRelation::Linked, ?Model $actor = null): KbArticleLink
    {
        if (! $this->tenants->belongsToTicketTenant($article, $ticket)) {
            throw CrossTenantException::forAssignment('article');
        }

        $link = DB::transaction(function () use ($article, $ticket, $relation, $actor): KbArticleLink {
            $model = Ticketing::kbArticleLinkModel();

            /** @var KbArticleLink $link */
            $link = $model::query()->withoutTenancy()->firstOrCreate(
                [
                    'article_id' => $article->getKey(),
                    'ticket_id' => $ticket->getKey(),
                    'relation' => $relation->value,
                ],
                array_merge($ticket->tenantAttributes(), $actor !== null ? [
                    'actor_type' => $actor->getMorphClass(),
                    'actor_id' => $actor->getKey(),
                ] : []),
            );

            if ($link->wasRecentlyCreated) {
                $this->audit->record(
                    ticket: $ticket,
                    event: 'article.linked',
                    actor: $actor,
                    context: ['article_id' => $article->getKey(), 'relation' => $relation->value],
                );
            }

            return $link;
        });

        if ($link->wasRecentlyCreated) {
            ArticleLinkedToTicket::dispatch($ticket, $link, $actor);
        }

        return $link;
    }
}
