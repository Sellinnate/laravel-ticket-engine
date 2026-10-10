<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Selli\Ticketing\Models\WidgetKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS for the widget API. A preflight carries no key (browsers never send
 * custom header values on OPTIONS), so it is answered generically; every real
 * request is then origin-checked against its widget key by ResolveWidgetKey,
 * and only an allowed origin is echoed back. No cookies are ever involved.
 *
 * @internal
 */
class WidgetCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);

            if (is_string($origin) && WidgetKey::normaliseOrigin($origin) !== null) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Ticketing-Key, X-Ticketing-Conversation');
                $response->headers->set('Access-Control-Max-Age', '600');
                $response->headers->set('Vary', 'Origin');
            }

            return $response;
        }

        /** @var Response $response */
        $response = $next($request);

        $key = $request->attributes->get(ResolveWidgetKey::ATTRIBUTE);

        if (is_string($origin) && $key instanceof WidgetKey && $key->allowsOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }
}
