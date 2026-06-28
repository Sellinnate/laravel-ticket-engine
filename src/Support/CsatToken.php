<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Illuminate\Support\Carbon;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;

/**
 * Stateless, signed CSAT tokens. A token binds a ticket id to an expiry with an
 * HMAC over a package secret, so the host app can hand a requester a rating link
 * without storing per-request state, and {@see SubmitCsat} can trust the ticket
 * the token names. URL-safe (base64url, no padding) so it drops straight into a
 * query string or path.
 */
class CsatToken
{
    /**
     * Build a token for a ticket, valid until $expiresAt and bound to a CSAT
     * cycle marker (the rating's per-request nonce, '' if none). The marker lets
     * a verifier reject a link issued for an earlier request cycle.
     */
    public static function issue(int|string $ticketId, Carbon $expiresAt, string $cycle = ''): string
    {
        $payload = self::encode([
            't' => (string) $ticketId,
            'e' => $expiresAt->getTimestamp(),
            'c' => $cycle,
        ]);

        return $payload.'.'.self::sign($payload);
    }

    /**
     * Return the claims of a valid, unexpired token (ticket id + cycle marker),
     * or null if the token is malformed, tampered with, or expired.
     *
     * @return array{ticket: string, cycle: string}|null
     */
    public static function verify(string $token, ?Carbon $now = null): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;

        // Constant-time comparison so a wrong signature leaks no timing data.
        if (! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        $data = self::decode($payload);

        if (! is_array($data) || ! isset($data['t'], $data['e']) || ! is_int($data['e'])) {
            return null;
        }

        $now ??= Carbon::now();

        if ($data['e'] < $now->getTimestamp()) {
            return null;
        }

        return [
            'ticket' => (string) $data['t'],
            'cycle' => isset($data['c']) && is_string($data['c']) ? $data['c'] : '',
        ];
    }

    protected static function sign(string $payload): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $payload, self::secret(), true));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function encode(array $data): string
    {
        return self::base64UrlEncode((string) json_encode($data));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function decode(string $payload): ?array
    {
        $json = self::base64UrlDecode($payload);

        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    protected static function secret(): string
    {
        $secret = config('ticketing.csat.token.secret') ?? config('app.key');

        if (! is_string($secret) || $secret === '') {
            // Fail closed: never sign with a guessable fallback, or anyone could
            // forge CSAT links for arbitrary tickets.
            throw new InvalidConfigurationException(
                'A CSAT token secret is required: set ticketing.csat.token.secret or the application key.'
            );
        }

        return $secret;
    }

    protected static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
