<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Selli\Ticketing\Exceptions\InvalidConfigurationException;

/**
 * Compact, signed token that maps a reply address back to a ticket.
 *
 * The token rides in the local-part of a tagged Reply-To
 * (e.g. support+t_<token>@example.com), so it must stay short enough for the
 * 64-char local-part limit. It binds only the ticket id with a truncated HMAC
 * over a package secret: an inbound reply can be threaded to the right ticket
 * without storing per-thread state, and a forged token can't point at an
 * arbitrary ticket. There is no expiry — a customer may reply to a thread at
 * any time, and the token alone never grants access, only correlation.
 */
class MailThreadToken
{
    /** Length of the truncated signature (base64url chars ≈ 120 bits). */
    private const SIGNATURE_LENGTH = 20;

    public static function issue(int|string $ticketId): string
    {
        // The id is used RAW (not base64url-encoded): auto-increment ids and
        // ULIDs are already local-part-safe, and encoding a 26-char ULID would
        // push support+t_<token>@… past the 64-char local-part limit.
        $payload = (string) $ticketId;

        return $payload.'.'.self::sign($payload);
    }

    /**
     * The ticket id a valid token names, or null if malformed/tampered.
     */
    public static function verify(string $token): ?string
    {
        // Split off the fixed-length signature from the right; the id (which
        // never contains a dot) is everything before it.
        $dot = strrpos($token, '.');

        if ($dot === false) {
            return null;
        }

        $payload = substr($token, 0, $dot);
        $signature = substr($token, $dot + 1);

        // Constant-time comparison so a wrong signature leaks no timing data.
        if ($payload === '' || ! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        return $payload;
    }

    /**
     * Extract the ticket id from the first recipient carrying a `+t_<token>`
     * sub-address whose token verifies, or null when none does.
     *
     * @param  iterable<string>  $recipients
     */
    public static function fromRecipients(iterable $recipients): ?string
    {
        foreach ($recipients as $recipient) {
            $token = self::tokenFromAddress($recipient);

            if ($token !== null && ($ticketId = self::verify($token)) !== null) {
                return $ticketId;
            }
        }

        return null;
    }

    /**
     * Build the tagged reply address for a ticket from a base address: e.g.
     * ("support@example.com", "<token>") → "support+t_<token>@example.com".
     */
    public static function tagAddress(string $baseAddress, string $token): string
    {
        $at = strrpos($baseAddress, '@');

        if ($at === false) {
            return $baseAddress;
        }

        $local = substr($baseAddress, 0, $at);
        $domain = substr($baseAddress, $at + 1);

        return $local.'+t_'.$token.'@'.$domain;
    }

    /**
     * Pull the raw token out of a `local+t_<token>@domain` address, or null.
     */
    public static function tokenFromAddress(string $address): ?string
    {
        $at = strrpos($address, '@');
        $local = $at === false ? $address : substr($address, 0, $at);

        if (! preg_match('/\+t_([^+@]+)$/', $local, $matches)) {
            return null;
        }

        return $matches[1];
    }

    protected static function sign(string $payload): string
    {
        return substr(self::base64UrlEncode(hash_hmac('sha256', $payload, self::secret(), true)), 0, self::SIGNATURE_LENGTH);
    }

    protected static function secret(): string
    {
        $secret = config('ticketing.mail.token.secret') ?? config('app.key');

        if (! is_string($secret) || $secret === '') {
            // Fail closed: never sign with a guessable fallback, or anyone could
            // forge thread addresses that post into arbitrary tickets.
            throw new InvalidConfigurationException(
                'A mail thread token secret is required: set ticketing.mail.token.secret or the application key.'
            );
        }

        return $secret;
    }

    protected static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
