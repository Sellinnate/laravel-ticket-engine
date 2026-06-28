<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Selli\Ticketing\Mail\InboundEmail;
use Selli\Ticketing\Mail\MailRoute;

/**
 * Resolves an inbound email's recipient address(es) to the owning tenant + the
 * ticket type a new ticket should use. Bind your own for dynamic routing (e.g.
 * a DB lookup per tenant inbox); the package ships a config-driven default.
 */
interface InboundMailRouter
{
    /**
     * The route for this email, or null when no recipient matches (the email is
     * then dropped — routing fails closed rather than guessing a tenant).
     */
    public function route(InboundEmail $email): ?MailRoute;
}
