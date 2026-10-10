<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\ArticleStatus;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Support\Ticketing;

class SaveArticleTool extends TicketingTool
{
    protected string $name = 'save-article';

    protected string $description = 'Write a help-center article or FAQ: creates a draft, or updates a draft by id. It never publishes nor edits a published article — a person does.';

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'title' => ['required_without:id', 'nullable', 'string', 'max:255'],
            'body' => ['required_without:id', 'nullable', 'string', 'max:100000'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'type' => ['nullable', 'in:article,faq'],
            'visibility' => ['nullable', 'in:public,customer,internal'],
            'locale' => ['nullable', 'string', 'max:12'],
        ]);

        $article = null;
        if (isset($data['id'])) {
            $article = $this->isKey((string) $data['id']) ? Ticketing::kbArticleModel()::query()->whereKey((string) $data['id'])->first() : null;

            if (! $article instanceof KbArticle) {
                throw ValidationException::withMessages(['id' => "Article [{$data['id']}] not found."]);
            }

            $this->authorize($request, 'update', $article);
        } else {
            $this->authorize($request, 'create', Ticketing::kbArticleModel());
        }

        $save = fn (): KbArticle => app(Ticketing::class)->knowledge()->save(new ArticleData(
            title: isset($data['title']) ? (string) $data['title'] : null,
            body: isset($data['body']) ? (string) $data['body'] : null,
            type: isset($data['type']) ? ArticleType::from((string) $data['type']) : null,
            locale: isset($data['locale']) ? (string) $data['locale'] : null,
            summary: isset($data['summary']) ? (string) $data['summary'] : null,
            visibility: isset($data['visibility']) ? KnowledgeVisibility::from((string) $data['visibility']) : null,
        ), $article, $user);

        // What customers read changes only through a person: the draft check
        // and the save share the article's row lock, so a publish that lands
        // in between is seen.
        $saved = $this->attempt(fn (): KbArticle => $article === null ? $save() : DB::transaction(function () use ($article, $data, $save): KbArticle {
            $locked = Ticketing::kbArticleModel()::query()->withoutTenancy()->lockForUpdate()->whereKey($article->getKey())->first();
            $status = $locked instanceof KbArticle ? $locked->status : null;

            if ($status !== ArticleStatus::Draft) {
                $state = $status->value ?? 'gone';

                throw ValidationException::withMessages(['id' => "Article [{$data['id']}] is {$state}: only drafts can be edited here. Create a new draft instead."]);
            }

            return $save();
        }));

        return Response::json([
            'id' => (string) $saved->getKey(),
            'slug' => $saved->slug,
            'title' => $saved->title,
            'status' => $saved->status->value,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The article to update; omit to create a draft.'),
            'title' => $schema->string(),
            'body' => $schema->string()->description('Markdown.'),
            'summary' => $schema->string(),
            'type' => $schema->string()->enum(['article', 'faq']),
            'visibility' => $schema->string()->enum(['public', 'customer', 'internal']),
            'locale' => $schema->string(),
        ];
    }
}
