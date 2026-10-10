<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

/**
 * A captcha (Turnstile, hCaptcha, reCAPTCHA…) check for anonymous widget
 * visitors, run when a conversation starts and before it is escalated to a
 * human. Bind your own; the default accepts everyone.
 */
interface HumanVerifier
{
    /**
     * @param  string|null  $token  the client-side challenge response
     */
    public function verify(?string $token, ?string $ip): bool;
}
