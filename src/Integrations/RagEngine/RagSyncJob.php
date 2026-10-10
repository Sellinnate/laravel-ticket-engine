<?php

declare(strict_types=1);

namespace Selli\Ticketing\Integrations\RagEngine;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Brings one article or ticket of the RAG index up to date (indexes it, or
 * removes it when it should not be there any more). Idempotent: it reads the
 * current state, so a late or repeated job does the right thing.
 */
class RagSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $kind,
        public int|string $id,
        public int|string|null $tenantId = null,
    ) {
        $this->onConnection(config('ticketing.queue.connection'));
        $this->onQueue(config('ticketing.queue.queue'));
        $this->afterCommit();
    }

    public static function sync(string $kind, int|string $id, int|string|null $tenantId = null): void
    {
        $job = new self($kind, $id, $tenantId);

        config('ticketing.rag.queue', true) === false ? dispatch_sync($job) : dispatch($job);
    }

    public function handle(RagKnowledge $rag): void
    {
        $this->kind === RagKnowledge::TYPE_ARTICLE
            ? $rag->syncArticle($this->id, $this->tenantId)
            : $rag->syncTicket($this->id, $this->tenantId);
    }
}
