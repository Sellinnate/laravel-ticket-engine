<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests;

use Selli\Ticketing\Tests\Fixtures\WordEmbedder;
use Sellinnate\RagEngine\Managers\EmbedderManager;

/**
 * Boots with the RAG Engine bridge on: the engine's in-memory vector store and
 * fake LLM, a word-overlap embedder, and inline indexing.
 */
class RagTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(EmbedderManager::class)->extend('words', fn (): WordEmbedder => new WordEmbedder);
    }

    public function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        config()->set('ticketing.rag.enabled', true);
        config()->set('ticketing.rag.queue', false);
        config()->set('ticketing.rag.knowledge_search', true);
        config()->set('ticketing.rag.min_score', 0.3);

        config()->set('rag-engine.embedders.words', ['driver' => 'words']);
        config()->set('rag-engine.defaults.embedder', 'words');
        config()->set('rag-engine.defaults.vector_store', 'memory');
        config()->set('rag-engine.defaults.llm', 'fake');
        config()->set('rag-engine.chunking.contextual_headers', false);
        config()->set('rag-engine.security.pii_redaction_enabled', false);
    }
}
