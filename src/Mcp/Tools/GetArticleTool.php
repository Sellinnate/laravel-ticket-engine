<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Support\Ticketing;

#[IsReadOnly]
class GetArticleTool extends TicketingTool
{
    protected string $name = 'get-article';

    protected string $description = 'Read one help-center article by slug.';

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'max:12'],
        ]);

        $article = app(Ticketing::class)->knowledge()->find((string) $data['slug'], KnowledgeAudience::for($user), isset($data['locale']) ? (string) $data['locale'] : null);

        if (! $article instanceof KbArticle || ! Gate::forUser($user)->allows('view', $article)) {
            throw ValidationException::withMessages(['slug' => "Article [{$data['slug']}] not found."]);
        }

        return Response::json([
            'id' => (string) $article->getKey(),
            'slug' => $article->slug,
            'locale' => $article->locale,
            'type' => $article->type->value,
            'title' => $article->title,
            'summary' => $article->summary,
            'body' => $article->body,
            'updated_at' => $article->updated_at?->toIso8601String(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->required(),
            'locale' => $schema->string(),
        ];
    }
}
