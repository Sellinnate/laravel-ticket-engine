<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Widget;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Selli\Ticketing\Contracts\AiSettingsResolver;
use Selli\Ticketing\Enums\AiMode;

/**
 * GET /config — what a widget needs to render: name, greeting, locale and
 * whether an AI assistant answers. Never an internal id.
 *
 * @internal
 */
class ConfigController extends WidgetController
{
    public function show(Request $request): JsonResponse
    {
        $key = $this->key($request);
        $mode = app(AiSettingsResolver::class)->mode($key->getAttribute($key->getTenantColumn()), $key->effectiveTicketType(), $key->channel);

        return response()->json([
            'data' => [
                'name' => $key->name,
                'greeting' => $key->setting('greeting'),
                'locale' => $key->setting('locale', config('app.locale')),
                'assistant' => $mode === AiMode::Auto,
                'require_email_to_escalate' => config('ticketing.widget.require_email_to_escalate', true) !== false,
                'max_message_length' => (int) config('ticketing.widget.max_message_length', 4000),
                'honeypot_field' => (string) config('ticketing.widget.honeypot_field', 'website'),
            ],
        ]);
    }
}
