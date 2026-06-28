<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * Visibility of a ticket message.
 *
 * Public messages are part of the conversation with the requester and are
 * delivered over the configured channels. Internal messages are notes shared
 * between agents and must never be exposed to the requester. The separation is
 * enforced at the query and policy layer, never left to a forgotten filter.
 */
enum MessageVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';

    public function isPublic(): bool
    {
        return $this === self::Public;
    }

    public function isInternal(): bool
    {
        return $this === self::Internal;
    }
}
