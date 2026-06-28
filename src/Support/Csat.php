<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Selli\Ticketing\Enums\CsatScale;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;

/**
 * Typed, fail-closed access to the CSAT configuration.
 */
class Csat
{
    public static function enabled(): bool
    {
        return config('ticketing.csat.enabled', true) !== false;
    }

    public static function autoRequest(): bool
    {
        return config('ticketing.csat.auto_request', true) !== false;
    }

    public static function scale(): CsatScale
    {
        $value = (string) config('ticketing.csat.scale', 'five_star');

        return CsatScale::tryFrom($value)
            ?? throw new InvalidConfigurationException("Unknown CSAT scale [{$value}].");
    }

    public static function tokenTtl(): int
    {
        $ttl = (int) config('ticketing.csat.token.ttl', 1209600); // 14 days

        if ($ttl <= 0) {
            // Fail closed: a misconfigured (zero/negative) TTL must not silently
            // fall back to a long-lived window.
            throw new InvalidConfigurationException('ticketing.csat.token.ttl must be a positive number of seconds.');
        }

        return $ttl;
    }
}
