<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

/**
 * The destination an inbound email's recipient resolves to: which tenant owns
 * it and which ticket type a new ticket should be opened as.
 */
final readonly class MailRoute
{
    public function __construct(
        public ?string $type,
        public int|string|null $tenant = null,
    ) {}
}
