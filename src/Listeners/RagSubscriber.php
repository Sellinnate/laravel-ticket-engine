<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Selli\Ticketing\Events\ArticleArchived;
use Selli\Ticketing\Events\ArticlePublished;
use Selli\Ticketing\Events\ArticleSaved;
use Selli\Ticketing\Events\RequesterAnonymized;
use Selli\Ticketing\Events\TicketClosed;
use Selli\Ticketing\Events\TicketErased;
use Selli\Ticketing\Events\TicketMerged;
use Selli\Ticketing\Events\TicketReopened;
use Selli\Ticketing\Events\TicketResolved;
use Selli\Ticketing\Integrations\RagEngine\RagKnowledge;
use Selli\Ticketing\Integrations\RagEngine\RagSyncJob;

/**
 * Keeps the RAG index in step with the help desk (`ticketing.rag.enabled`):
 * articles on publish/save/archive, tickets on resolve/close/reopen, and the
 * GDPR purges — an erased ticket, a merged-away ticket, an anonymised
 * requester's conversations.
 *
 * @internal
 */
class RagSubscriber implements Subscriber
{
    public function __construct(protected RagKnowledge $rag) {}

    public function onArticle(ArticlePublished|ArticleSaved|ArticleArchived $event): void
    {
        if (config('ticketing.rag.index.articles', true) !== false) {
            RagSyncJob::sync(RagKnowledge::TYPE_ARTICLE, $event->article->getKey(), $event->article->getAttribute($event->article->getTenantColumn()));
        }
    }

    public function onTicket(TicketResolved|TicketClosed|TicketReopened $event): void
    {
        if (config('ticketing.rag.index.tickets', true) !== false) {
            RagSyncJob::sync(RagKnowledge::TYPE_TICKET, $event->ticket->getKey(), $event->ticket->getAttribute($event->ticket->getTenantColumn()));
        }
    }

    public function onErased(TicketErased $event): void
    {
        RagSyncJob::sync(RagKnowledge::TYPE_TICKET, $event->ticketId, $event->tenantId);
    }

    public function onMerged(TicketMerged $event): void
    {
        $tenant = $event->target->getAttribute($event->target->getTenantColumn());

        foreach ($event->sourceIds as $id) {
            RagSyncJob::sync(RagKnowledge::TYPE_TICKET, $id, $tenant);
        }

        RagSyncJob::sync(RagKnowledge::TYPE_TICKET, $event->target->getKey(), $tenant);
    }

    /**
     * Right away (after commit), not queued: an erasure is not deferred.
     */
    public function onAnonymized(RequesterAnonymized $event): void
    {
        $this->rag->forgetTicketsOf($event->requester);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            ArticlePublished::class => 'onArticle',
            ArticleSaved::class => 'onArticle',
            ArticleArchived::class => 'onArticle',
            TicketResolved::class => 'onTicket',
            TicketClosed::class => 'onTicket',
            TicketReopened::class => 'onTicket',
            TicketErased::class => 'onErased',
            TicketMerged::class => 'onMerged',
            RequesterAnonymized::class => 'onAnonymized',
        ];
    }
}
