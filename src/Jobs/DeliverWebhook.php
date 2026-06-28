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
            // Never follow redirects: a public/allow-listed first hop must not be
            // able to bounce the request to a private/loopback target (SSRF).
            ->withoutRedirecting()
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

        // Strip the brackets from an IPv6 literal host ([::1]).
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

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

        $ips = $this->resolveIps($host);

        if ($ips === []) {
            // Fail closed: if we can't resolve the host to verify it's public,
            // don't hand it to the HTTP client (which may resolve it differently).
            throw new InvalidConfigurationException("Webhook host [{$host}] could not be resolved to verify it is public.");
        }

        // Block if ANY resolved address (v4 or v6) is private/reserved.
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidConfigurationException("Webhook host [{$host}] resolves to a private or reserved address.");
            }
        }
    }

    /**
     * Resolve a host to its IP addresses (a literal IP is returned as-is). Covers
     * both A and AAAA records so an IPv6-only private target is caught too. An
     * unresolvable host yields no IPs and is left for the HTTP client to fail.
     *
     * @return list<string>
     */
    protected function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        /** @var list<array<string, mixed>>|false $records */
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];      // A
                }

                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];    // AAAA
                }
            }
        }

        return $ips;
    }

    public function failed(Throwable $exception): void
    {
        WebhookFailed::dispatch($this->url, $this->payload, $exception->getMessage());
    }
}
