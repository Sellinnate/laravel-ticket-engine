<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Illuminate\Support\Carbon;

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
     * Build a token for a ticket, valid until $expiresAt.
     */
    public static function issue(int|string $ticketId, Carbon $expiresAt): string
    {
        $payload = self::encode([
            't' => (string) $ticketId,
            'e' => $expiresAt->getTimestamp(),
        ]);

        return $payload.'.'.self::sign($payload);
    }

    /**
     * Return the ticket id a valid, unexpired token names, or null if the token
     * is malformed, tampered with, or expired.
     */
    public static function verify(string $token, ?Carbon $now = null): ?string
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

        return (string) $data['t'];
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

        return is_string($secret) && $secret !== '' ? $secret : 'ticketing-csat';
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
