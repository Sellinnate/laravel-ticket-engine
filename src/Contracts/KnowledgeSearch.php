<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Selli\Ticketing\Knowledge\KnowledgeQuery;
use Selli\Ticketing\Knowledge\KnowledgeResult;

/**
 * Searches the knowledge base for an audience. The package ships a portable
 * keyword implementation; the RAG Engine bridge binds a semantic one. Any
 * implementation must only ever return articles the query's audience may read.
 */
interface KnowledgeSearch
{
    /**
     * Best matches first.
     *
     * @return list<KnowledgeResult>
     */
    public function search(KnowledgeQuery $query): array;
}
