<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Widget;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Selli\Ticketing\Contracts\HumanVerifier;
use Selli\Ticketing\Conversations\ConversationService;
use Selli\Ticketing\Http\Middleware\ResolveWidgetKey;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\WidgetKey;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shared helpers for the public widget API: the resolved key, bearer-token
 * conversation authentication, the honeypot and the captcha hook.
 *
 * @internal
 */
abstract class WidgetController
{
    protected function key(Request $request): WidgetKey
    {
        $key = $request->attributes->get(ResolveWidgetKey::ATTRIBUTE);

        if (! $key instanceof WidgetKey) {
            throw new HttpException(401, 'Unknown or revoked widget key.');
        }

        return $key;
    }

    /**
     * The conversation named in the URL, authenticated by its bearer token and
     * bound to this widget key. Any mismatch is a plain 404.
     */
    protected function conversation(Request $request, string $id): Conversation
    {
        $token = $request->bearerToken();
        $conversation = is_string($token) && $token !== ''
            ? app(ConversationService::class)->authenticate($id, $token, $this->key($request))
            : null;

        if ($conversation === null) {
            throw new HttpException(404, 'Conversation not found.');
        }

        return $conversation;
    }

    /**
     * Bots fill every field: the honeypot must arrive empty.
     */
    protected function guardHoneypot(Request $request): void
    {
        $field = (string) config('ticketing.widget.honeypot_field', 'website');

        if ($field !== '' && filled($request->input($field))) {
            throw ValidationException::withMessages(['message' => 'The request could not be processed.']);
        }
    }

    /**
     * The captcha check for visitors without a verified identity.
     */
    protected function guardHuman(Request $request, bool $verified): void
    {
        if ($verified) {
            return;
        }

        $token = $request->input('captcha_token');

        if (! app(HumanVerifier::class)->verify(is_string($token) ? $token : null, $request->ip())) {
            throw ValidationException::withMessages(['captcha_token' => 'Please confirm you are not a robot.']);
        }
    }

    protected function audience(Request $request, ?Conversation $conversation = null): KnowledgeAudience
    {
        $key = $this->key($request);
        $identified = $conversation !== null && ($conversation->identity_verified || $conversation->requester_type !== null);

        return KnowledgeAudience::scoped($key->audience_type, $key->audience_id, $identified);
    }
}
