<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Illuminate\Http\Client\PendingRequest;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;

/**
 * Shared SSRF guard for all outbound HTTP the package makes (automation
 * webhooks AND Slack/Teams notifications): HTTP(S)-only, optional host
 * allow-list, and — by default — blocking hosts that resolve to private /
 * loopback / link-local-metadata addresses, with the validated IP pinned onto
 * the connection to close the DNS-rebinding window. Driven by `ticketing.webhooks.*`.
 */
class WebhookGuard
{
    /**
     * Validate a destination URL, returning the IP to pin (or null when no
     * pinning is needed: allow-listed host, or private blocking disabled).
     * Throws on a disallowed destination.
     */
    public static function assertAllowed(string $url): ?string
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
            if (! in_array($host, $allowed, true)) {
                throw new InvalidConfigurationException("Webhook host [{$host}] is not allow-listed.");
            }

            return null;
        }

        if (config('ticketing.webhooks.block_private', true) === false) {
            return null;
        }

        $ips = self::resolveIps($host);

        if ($ips === []) {
            throw new InvalidConfigurationException("Webhook host [{$host}] could not be resolved to verify it is public.");
        }

        foreach ($ips as $ip) {
            $check = self::canonicalIp($ip);

            if (filter_var($check, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidConfigurationException("Webhook host [{$host}] resolves to a private or reserved address.");
            }
        }

        return $ips[0];
    }

    /**
     * Apply the validated-IP pin (CURLOPT_RESOLVE) to a request, failing closed
     * if curl isn't available to honour it.
     */
    public static function applyPin(PendingRequest $request, string $url, ?string $pinnedIp): PendingRequest
    {
        if ($pinnedIp === null) {
            return $request;
        }

        if (! extension_loaded('curl')) {
            throw new InvalidConfigurationException(
                'The webhook SSRF guard requires the curl extension to pin the validated address; '
                .'install ext-curl or use webhooks.allowed_hosts.'
            );
        }

        return $request->withOptions([
            'curl' => [CURLOPT_RESOLVE => self::curlResolveEntries($url, $pinnedIp)],
        ]);
    }

    /**
     * @return list<string>
     */
    protected static function curlResolveEntries(string $url, string $ip): array
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_int($port)) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        // libcurl (>= 7.57) requires an IPv6 ADDRESS to be bracketed in a
        // CURLOPT_RESOLVE rule.
        $address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$ip}]" : $ip;

        return ["{$host}:{$port}:{$address}"];
    }

    /**
     * Unwrap an IPv4-embedding IPv6 to its trailing IPv4 so the private/reserved
     * check can't be evaded through it. Covers the IPv4-mapped range
     * (::ffff:a.b.c.d) and the NAT64 well-known prefix (64:ff9b::/96) — an AAAA
     * of 64:ff9b::a9fe:a9fe looks "public" but a NAT64 gateway translates it to
     * 169.254.169.254 (or any private IPv4).
     */
    protected static function canonicalIp(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        // ::ffff:0:0/96 (IPv4-mapped) or 64:ff9b::/96 (NAT64 well-known prefix).
        $isMapped = substr($packed, 0, 10) === str_repeat("\0", 10) && substr($packed, 10, 2) === "\xff\xff";
        $isNat64 = substr($packed, 0, 12) === "\x00\x64\xff\x9b".str_repeat("\0", 8);

        if ($isMapped || $isNat64) {
            $v4 = inet_ntop(substr($packed, 12, 4));

            if ($v4 !== false) {
                return $v4;
            }
        }

        return $ip;
    }

    /**
     * Resolve a host to its IPs (a literal IP returns as-is), covering A and AAAA.
     *
     * @return list<string>
     */
    protected static function resolveIps(string $host): array
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
                    $ips[] = $record['ip'];
                }

                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }
}
