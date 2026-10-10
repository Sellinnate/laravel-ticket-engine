<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Knowledge\KnowledgeResult;
use Selli\Ticketing\Support\Ticketing;

#[IsReadOnly]
class SearchKnowledgeTool extends TicketingTool
{
    protected string $name = 'search-knowledge-base';

    protected string $description = 'Search the help-center articles and FAQs you may read.';

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $data = $request->validate([
            'query' => ['required', 'string', 'max:200'],
            'locale' => ['nullable', 'string', 'max:12'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $results = app(Ticketing::class)->knowledge()->search(
            (string) $data['query'],
            KnowledgeAudience::for($user),
            isset($data['locale']) ? (string) $data['locale'] : null,
            (int) ($data['limit'] ?? 5),
        );

        // What a direct read would refuse never shows up in a search either.
        $gate = Gate::forUser($user);
        $results = array_values(array_filter($results, fn (KnowledgeResult $result): bool => $gate->allows('view', $result->article)));

        return Response::json(['results' => array_map(fn (KnowledgeResult $result): array => $result->toArray(), $results)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The question or keywords.')->required(),
            'locale' => $schema->string(),
            'limit' => $schema->integer()->min(1)->max(10)->default(5),
        ];
    }
}
