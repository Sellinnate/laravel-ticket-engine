<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Widget;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Selli\Ticketing\Conversations\ConversationService;
use Selli\Ticketing\Conversations\VisitorIdentity;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Exceptions\ConversationException;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Support\Ticketing;

/**
 * The visitor side of a conversation: start, read, write, ask for a human,
 * close. Authenticated by the conversation's bearer token (handed out once on
 * start); the URL carries only the opaque public id.
 *
 * @internal
 */
class ConversationController extends WidgetController
{
    public function __construct(protected ConversationService $conversations) {}

    public function store(Request $request): JsonResponse
    {
        $this->guardHoneypot($request);

        $data = $request->validate([
            'identity' => ['nullable', 'array'],
            'identity.email' => ['nullable', 'string', 'max:255'],
            'identity.name' => ['nullable', 'string', 'max:255'],
            'identity.user_id' => ['nullable', 'string', 'max:255'],
            'identity.signature' => ['nullable', 'string', 'max:128'],
            'locale' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{2,8})?$/'],
            'captcha_token' => ['nullable', 'string', 'max:4096'],
        ]);

        $key = $this->key($request);
        $identity = is_array($data['identity'] ?? null) ? VisitorIdentity::fromArray($data['identity']) : null;
        $verified = $identity !== null && app(Ticketing::class)->widgetKeys()->verifyIdentity($key, $identity);

        $this->guardHuman($request, $verified);

        ['conversation' => $conversation, 'token' => $token] = $this->conversations->start($key, $identity, [
            'locale' => $data['locale'] ?? null,
            'origin' => $request->headers->get('Origin'),
            'ip' => $request->ip(),
        ]);

        return response()->json(['data' => array_merge($this->present($conversation), [
            'token' => $token,
            'expires_at' => $this->expiresAt($conversation),
        ])], 201);
    }

    public function messages(Request $request, string $conversation): JsonResponse
    {
        $model = $this->conversation($request, $conversation);
        $since = $request->query('since');

        try {
            $sinceAt = is_string($since) && $since !== '' ? Carbon::parse($since) : null;
        } catch (\Throwable) {
            $sinceAt = null;
        }

        return response()->json(['data' => array_merge($this->present($model), [
            'messages' => $this->conversations->timeline($model, $sinceAt),
        ])]);
    }

    public function post(Request $request, string $conversation): JsonResponse
    {
        $this->guardHoneypot($request);
        $model = $this->conversation($request, $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.max(1, (int) config('ticketing.widget.max_message_length', 4000))],
        ]);

        try {
            $this->conversations->post($model, (string) $data['body']);
        } catch (ConversationException $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }

        $model->refresh();

        return response()->json(['data' => array_merge($this->present($model), [
            'messages' => $this->conversations->timeline($model),
        ])], 201);
    }

    public function escalate(Request $request, string $conversation): JsonResponse
    {
        $this->guardHoneypot($request);
        $model = $this->conversation($request, $conversation);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'captcha_token' => ['nullable', 'string', 'max:4096'],
        ]);

        $this->guardHuman($request, $model->identity_verified);

        try {
            $ticket = $this->conversations->escalate(
                $model,
                EscalationReason::HumanRequested,
                isset($data['name']) ? (string) $data['name'] : null,
                isset($data['email']) ? (string) $data['email'] : null,
            );
        } catch (ConversationException $exception) {
            throw ValidationException::withMessages(['conversation' => $exception->getMessage()]);
        }

        if ($ticket === null) {
            throw ValidationException::withMessages(['email' => ConversationException::contactRequired()->getMessage()]);
        }

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    public function close(Request $request, string $conversation): JsonResponse
    {
        $model = $this->conversation($request, $conversation);

        return response()->json(['data' => $this->present($this->conversations->close($model))]);
    }

    /**
     * @return array{id: string, status: string, ticket: string|null, handoff_pending: bool, identity_verified: bool}
     */
    protected function present(Conversation $conversation): array
    {
        $reference = $conversation->ticket_id !== null
            ? Ticketing::ticketModel()::query()->withoutTenancy()->whereKey($conversation->ticket_id)->value('reference')
            : null;

        return [
            'id' => $conversation->public_id,
            'status' => $conversation->status->value,
            'ticket' => is_string($reference) ? $reference : null,
            'handoff_pending' => ($conversation->meta['handoff_pending'] ?? false) === true,
            'identity_verified' => $conversation->identity_verified,
        ];
    }

    protected function expiresAt(Conversation $conversation): ?string
    {
        $ttl = (int) config('ticketing.widget.token_ttl', 604800);

        return $ttl > 0 ? $conversation->created_at?->copy()->addSeconds($ttl)->toIso8601String() : null;
    }
}
