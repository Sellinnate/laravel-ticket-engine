<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Selli\Ticketing\Contracts\HumanVerifier;

/**
 * No captcha: every visitor is accepted. The rate limits, honeypot and size
 * caps still apply.
 */
class NullHumanVerifier implements HumanVerifier
{
    public function verify(?string $token, ?string $ip): bool
    {
        return true;
    }
}
