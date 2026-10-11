<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Widget;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Selli\Ticketing\Conversations\ConversationService;
use Selli\Ticketing\Knowledge\KnowledgeBase;
use Selli\Ticketing\Knowledge\KnowledgeResult;
use Selli\Ticketing\Models\Conversation;

/**
 * The public help center behind a widget: deflection search, reading an
 * article and voting on it. Articles are addressed by slug + locale; only what
 * the widget's audience may read is ever returned.
 *
 * @internal
 */
class KnowledgeController extends WidgetController
{
    public function __construct(protected KnowledgeBase $knowledge) {}

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'locale' => ['nullable', 'string', 'max:12'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $results = $this->knowledge->search(
            (string) $data['q'],
            $this->audience($request, $this->optionalConversation($request)),
            isset($data['locale']) ? (string) $data['locale'] : null,
            isset($data['limit']) ? (int) $data['limit'] : 5,
        );

        return response()->json(['data' => array_map(fn (KnowledgeResult $result): array => [
            'slug' => $result->article->slug,
            'locale' => $result->article->locale,
            'type' => $result->article->type->value,
            'title' => $result->article->title,
            'excerpt' => $result->excerpt,
            'score' => round($result->score, 4),
        ], $results)]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $locale = $request->query('locale');
        $article = $this->knowledge->find($slug, $this->audience($request, $this->optionalConversation($request)), is_string($locale) ? $locale : null);

        if ($article === null) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        return response()->json(['data' => [
            'slug' => $article->slug,
            'locale' => $article->locale,
            'type' => $article->type->value,
            'title' => $article->title,
            'summary' => $article->summary,
            'body' => $article->body,
            'body_format' => $article->body_format->value,
            'updated_at' => $article->updated_at?->toIso8601String(),
        ]]);
    }

    public function feedback(Request $request, string $slug): JsonResponse
    {
        $this->guardHoneypot($request);

        $data = $request->validate([
            'helpful' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'locale' => ['nullable', 'string', 'max:12'],
        ]);

        $conversation = $this->optionalConversation($request);
        $article = $this->knowledge->find($slug, $this->audience($request, $conversation), isset($data['locale']) ? (string) $data['locale'] : null);

        if ($article === null) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        // One vote per conversation, else per (hashed) IP and key.
        $voter = $conversation !== null
            ? 'conversation:'.$conversation->public_id
            : 'ip:'.hash('sha256', (string) $request->ip().'|'.$this->key($request)->public_key);

        $this->knowledge->feedback($article, (bool) $data['helpful'], $voter, isset($data['comment']) ? (string) $data['comment'] : null);

        return response()->json(['data' => ['recorded' => true]], 201);
    }

    /**
     * The conversation when the request carries one (X-Ticketing-Conversation
     * + bearer token); identified visitors then also see customer articles.
     */
    protected function optionalConversation(Request $request): ?Conversation
    {
        $id = $request->headers->get('X-Ticketing-Conversation');
        $token = $request->bearerToken();

        if (! is_string($id) || $id === '' || ! is_string($token) || $token === '') {
            return null;
        }

        return app(ConversationService::class)->authenticate($id, $token, $this->key($request));
    }
}
