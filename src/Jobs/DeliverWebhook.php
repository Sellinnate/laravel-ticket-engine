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
        $this->assertUrlAllowed($this->url);

        $body = json_encode($this->payload, JSON_THROW_ON_ERROR);
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

    /**
     * Guard the destination: HTTP(S) only, and — unless an explicit host
     * allow-list is configured — block requests that resolve to private,
     * loopback or link-local/metadata addresses (SSRF). An unresolvable host is
     * left to the HTTP client to fail.
     */
    protected function assertUrlAllowed(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidConfigurationException("Webhook url must be http(s): [{$url}].");
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '') {
            throw new InvalidConfigurationException("Webhook url has no host: [{$url}].");
        }

        /** @var list<string> $allowed */
        $allowed = (array) config('ticketing.webhooks.allowed_hosts', []);

        if ($allowed !== []) {
            // An explicit allow-list is authoritative; nothing else is reachable.
            if (! in_array($host, $allowed, true)) {
                throw new InvalidConfigurationException("Webhook host [{$host}] is not allow-listed.");
            }

            return;
        }

        if (config('ticketing.webhooks.block_private', true) === false) {
            return;
        }

        // Resolve a hostname to an IP; a literal IP is used as-is. Only block when
        // we actually have an IP that falls in a private/reserved range.
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (filter_var($ip, FILTER_VALIDATE_IP)
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidConfigurationException("Webhook host [{$host}] resolves to a private or reserved address.");
        }
    }

    public function failed(Throwable $exception): void
    {
        WebhookFailed::dispatch($this->url, $this->payload, $exception->getMessage());
    }
}
