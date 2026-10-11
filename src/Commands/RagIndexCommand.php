<?php

declare(strict_types=1);

namespace Selli\Ticketing\Commands;

use Illuminate\Console\Command;
use Selli\Ticketing\Integrations\RagEngine\RagKnowledge;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;
use Sellinnate\RagEngine\RagEngine;

/**
 * (Re)builds and repairs the RAG index: every article, resolved or closed
 * ticket and canned response is synced (indexed, or removed when no longer
 * eligible), then documents whose source is gone are purged. Safe to run
 * again — documents are versioned by key, unchanged content is not
 * re-embedded.
 *
 * @internal
 */
class RagIndexCommand extends Command
{
    protected $signature = 'ticketing:rag:index
        {--articles : Only the knowledge-base articles}
        {--tickets : Only the resolved and closed tickets}
        {--canned : Only the canned responses}';

    protected $description = 'Index the knowledge base and resolved tickets in the RAG engine.';

    public function handle(RagKnowledge $rag): int
    {
        if (! class_exists(RagEngine::class)) {
            $this->error('The RAG bridge needs sellinnate/rag-engine: composer require sellinnate/rag-engine');

            return self::FAILURE;
        }

        $all = ! $this->option('articles') && ! $this->option('tickets') && ! $this->option('canned');

        // Every source row is synced — which also removes what is no longer
        // eligible (unpublished, reopened, inactive) — then documents whose
        // source is gone are purged.
        if ($all || $this->option('articles')) {
            $count = 0;
            Ticketing::kbArticleModel()::query()->withoutTenancy()->withTrashed()
                ->chunkById(200, function ($articles) use ($rag, &$count): void {
                    foreach ($articles as $article) {
                        /** @var KbArticle $article */
                        $rag->syncArticle($article->getKey(), $article->getAttribute($article->getTenantColumn()));
                        $count++;
                    }
                });

            $this->line("  articles — {$count}");
        }

        if ($all || $this->option('tickets')) {
            $count = 0;
            Ticketing::ticketModel()::query()->withoutTenancy()
                ->where(fn ($query) => $query->whereNotNull('resolved_at')->orWhereNotNull('closed_at'))
                ->chunkById(200, function ($tickets) use ($rag, &$count): void {
                    foreach ($tickets as $ticket) {
                        /** @var Ticket $ticket */
                        $rag->syncTicket($ticket->getKey(), $ticket->getAttribute($ticket->getTenantColumn()));
                        $count++;
                    }
                });

            $this->line("  tickets — {$count}");
        }

        if ($all || $this->option('canned')) {
            $count = 0;
            Ticketing::cannedResponseModel()::query()->withoutTenancy()
                ->chunkById(200, function ($responses) use ($rag, &$count): void {
                    foreach ($responses as $response) {
                        $rag->syncCannedResponse($response->getKey(), $response->getAttribute($response->getTenantColumn()));
                        $count++;
                    }
                });

            $this->line("  canned responses — {$count}");
        }

        $this->line('  index documents checked — '.$rag->reconcile());

        return self::SUCCESS;
    }
}
