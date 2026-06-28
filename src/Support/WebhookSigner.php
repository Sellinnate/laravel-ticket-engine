<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

/**
 * HMAC-SHA256 signing for outbound webhooks, mirroring the GitHub/Stripe-style
 * `sha256=...` header so receivers can verify authenticity and integrity.
 */
class WebhookSigner
{
    public const HEADER = 'X-Ticketing-Signature';

    public static function sign(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public static function verify(string $body, string $secret, string $signature): bool
    {
        return hash_equals(self::sign($body, $secret), $signature);
    }
}
