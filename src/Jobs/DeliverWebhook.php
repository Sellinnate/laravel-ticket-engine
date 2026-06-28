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
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
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
        // Only ever call out over HTTP(S): a misconfigured rule must not be able
        // to reach file:// or gopher:// style targets.
        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidConfigurationException("Webhook url must be http(s): [{$this->url}].");
        }

        $body = (string) json_encode($this->payload);
        $headers = ['Content-Type' => 'application/json'];

        $secret = $this->secret ?? config('ticketing.webhooks.secret');

        if (is_string($secret) && $secret !== '') {
            $headers[WebhookSigner::HEADER] = WebhookSigner::sign($body, $secret);
        }

        $timeout = max(1, (int) config('ticketing.webhooks.timeout', 5));

        Http::timeout($timeout)
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
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
