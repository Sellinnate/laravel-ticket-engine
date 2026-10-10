<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Selli\Ticketing\Conversations\WidgetKeys;
use Selli\Ticketing\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the public widget key (X-Ticketing-Key header), rejects revoked
 * keys and browser origins outside the key's allow-list, and runs the request
 * as the key's tenant. Server-to-server calls (no Origin header) are allowed;
 * they hold no browser session to abuse and stay rate limited.
 *
 * @internal
 */
class ResolveWidgetKey
{
    public const ATTRIBUTE = 'ticketing.widget_key';

    public function __construct(
        protected WidgetKeys $keys,
        protected TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $value = $request->headers->get('X-Ticketing-Key');
        $key = is_string($value) ? $this->keys->find(trim($value)) : null;

        if ($key === null) {
            return response()->json(['message' => 'Unknown or revoked widget key.'], 401);
        }

        $origin = $request->headers->get('Origin');

        if (is_string($origin) && $origin !== '' && ! $key->allowsOrigin($origin)) {
            return response()->json(['message' => 'This origin may not use this widget key.'], 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $key);

        // Throttled bookkeeping: at most one write per key every five minutes.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinutes(5))) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        /** @var Response */
        return $this->tenant->forTenant($key->getAttribute($key->getTenantColumn()), fn () => $next($request));
    }
}
