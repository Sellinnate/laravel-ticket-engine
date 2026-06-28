<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when an outbound webhook exhausts its retries (dead-letter). The hook
 * for alerting, recording the failure, or persisting it for a later replay.
 */
class WebhookFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $url,
        public array $payload,
        public string $error,
    ) {}
}
