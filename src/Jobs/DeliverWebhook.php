<?php

declare(strict_types=1);

namespace Selli\Ticketing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Selli\Ticketing\Events\WebhookFailed;
use Selli\Ticketing\Support\WebhookGuard;
use Selli\Ticketing\Support\WebhookSigner;
use Throwable;

/**
 * Delivers an outbound webhook off the request: signs the JSON body with HMAC,
 * POSTs it, and retries on failure. When the retries are exhausted it emits
 * {@see WebhookFailed} (dead-letter) so the host can alert or replay.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $url,
        public array $payload,
        public ?string $secret = null,
    ) {
        $this->tries = max(1, (int) config('ticketing.webhooks.tries', 3));
        $this->onConnection(config('ticketing.queue.connection'));
        $this->onQueue(config('ticketing.queue.queue'));
    }

    public function handle(): void
    {
        $pinnedIp = WebhookGuard::assertAllowed($this->url);

        $body = json_encode($this->payload, JSON_THROW_ON_ERROR);
        $headers = ['Content-Type' => 'application/json'];

        $secret = $this->secret ?? config('ticketing.webhooks.secret');

        if (is_string($secret) && $secret !== '') {
            $headers[WebhookSigner::HEADER] = WebhookSigner::sign($body, $secret);
        }

        $timeout = max(1, (int) config('ticketing.webhooks.timeout', 5));

        $request = Http::timeout($timeout)
            // Never follow redirects: a public/allow-listed first hop must not be
            // able to bounce the request to a private/loopback target (SSRF).
            ->withoutRedirecting()
            ->withHeaders($headers)
            ->withBody($body, 'application/json');

        WebhookGuard::applyPin($request, $this->url, $pinnedIp)
            ->post($this->url)
            ->throw(); // non-2xx -> RequestException -> retry
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function failed(Throwable $exception): void
    {
        WebhookFailed::dispatch($this->url, $this->payload, $exception->getMessage());
    }
}
