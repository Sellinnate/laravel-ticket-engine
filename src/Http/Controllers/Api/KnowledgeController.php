<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Selli\Ticketing\Data\ArticleData;
use Selli\Ticketing\Enums\ArticleLinkRelation;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Http\Requests\StoreArticleRequest;
use Selli\Ticketing\Http\Resources\ArticleResource;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Knowledge\KnowledgeBase;
use Selli\Ticketing\Knowledge\KnowledgeResult;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Support\Ticketing;

/**
 * The knowledge base over the authenticated API: search and read for everyone
 * (by audience), write/publish/archive for agents, votes and ticket links.
 *
 * @internal
 */
class KnowledgeController extends Controller
{
    public function __construct(protected KnowledgeBase $knowledge) {}

    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $audience = KnowledgeAudience::for($request->user());
        $locale = is_string($request->query('locale')) ? (string) $request->query('locale') : null;
        $type = is_string($request->query('type')) ? ArticleType::tryFrom((string) $request->query('type')) : null;
        $category = $request->query('category');
        $category = is_scalar($category) ? (string) $category : null;

        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $results = $this->knowledge->search(mb_substr($q, 0, 200), $audience, $locale, 20, $type, $category);

            return response()->json(['data' => array_map(fn (KnowledgeResult $result): array => $result->toArray(), $results)]);
        }

        return ArticleResource::collection($this->knowledge->articles($audience, $locale, $category, $type));
    }

    public function show(Request $request, string $article): ArticleResource
    {
        $model = $this->resolveArticle($article);
        Gate::forUser($request->user())->authorize('view', $model);

        return new ArticleResource($model);
    }

    public function store(StoreArticleRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', Ticketing::kbArticleModel());

        $article = $this->knowledge->save($this->data($request), null, $this->actor($request));

        return (new ArticleResource($article))->response()->setStatusCode(201);
    }

    public function update(StoreArticleRequest $request, string $article): ArticleResource
    {
        $model = $this->resolveArticle($article);
        Gate::forUser($request->user())->authorize('update', $model);

        return new ArticleResource($this->knowledge->save($this->data($request), $model, $this->actor($request)));
    }

    public function publish(Request $request, string $article): ArticleResource
    {
        $model = $this->resolveArticle($article);
        Gate::forUser($request->user())->authorize('publish', $model);

        return new ArticleResource($this->knowledge->publish($model, $this->actor($request)));
    }

    public function archive(Request $request, string $article): ArticleResource
    {
        $model = $this->resolveArticle($article);
        Gate::forUser($request->user())->authorize('archive', $model);

        return new ArticleResource($this->knowledge->archive($model, $this->actor($request)));
    }

    public function feedback(Request $request, string $article): JsonResponse
    {
        $model = $this->resolveArticle($article);
        Gate::forUser($request->user())->authorize('view', $model);

        $data = $request->validate([
            'helpful' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $this->actor($request);
        $voter = $user ?? 'ip:'.hash('sha256', (string) $request->ip());

        $this->knowledge->feedback($model, (bool) $data['helpful'], $voter, isset($data['comment']) ? (string) $data['comment'] : null);

        return response()->json(['data' => ['recorded' => true]], 201);
    }

    public function link(Request $request, string $ticket): JsonResponse
    {
        $ticketModel = $this->resolveTicket($ticket);
        $this->authorizeTicket($request->user(), 'linkArticle', $ticketModel);

        $data = $request->validate([
            'article_id' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('The article id must be a scalar value.');
                }
            }],
            'relation' => ['nullable', 'in:resolved_by,suggested,linked'],
        ]);

        $article = $this->resolveArticle((string) $data['article_id']);

        $link = $this->knowledge->link(
            $article,
            $ticketModel,
            ArticleLinkRelation::tryFrom((string) ($data['relation'] ?? 'linked')) ?? ArticleLinkRelation::Linked,
            $this->actor($request),
        );

        return response()->json(['data' => [
            'article_id' => $link->article_id,
            'ticket_id' => $link->ticket_id,
            'relation' => $link->relation->value,
        ]], 201);
    }

    protected function data(StoreArticleRequest $request): ArticleData
    {
        $string = fn (string $key): ?string => $request->filled($key) ? (string) $request->input($key) : null;
        $keywords = $request->input('keywords');

        return new ArticleData(
            title: $string('title'),
            body: $string('body'),
            type: ArticleType::tryFrom((string) $request->input('type')),
            locale: $string('locale'),
            slug: $string('slug'),
            summary: $string('summary'),
            keywords: is_array($keywords) ? array_values(array_map('strval', $keywords)) : null,
            categoryId: $request->filled('category_id') ? (string) $request->input('category_id') : null,
            visibility: KnowledgeVisibility::tryFrom((string) $request->input('visibility')),
            bodyFormat: BodyFormat::tryFrom((string) $request->input('body_format')),
        );
    }

    /**
     * Resolve through the configured, tenant-scoped model: another tenant's
     * article is a 404.
     */
    protected function resolveArticle(string $key): KbArticle
    {
        self::assertKey($key);

        /** @var KbArticle $article */
        $article = Ticketing::kbArticleModel()::query()->whereKey($key)->firstOrFail();

        return $article;
    }
}
