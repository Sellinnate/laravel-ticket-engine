<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Selli\Ticketing\Broadcasting\DefaultChannelAuthorizer;

/**
 * Authorizes a connecting user for the package's private broadcast channels.
 *
 * Bind your own implementation (e.g. delegating to your ticket policies) on
 * the container; the package ships {@see DefaultChannelAuthorizer},
 * which is tenant-scoped and role-aware out of the box.
 */
interface ChannelAuthorizer
{
    /** The tenant-wide agent feed — agents of that tenant only. */
    public function forTenantTickets(Authenticatable $user, int|string $tenantId): bool;

    /** An agent's personal feed — that same agent (morph type + id) only. */
    public function forAgent(Authenticatable $user, int|string $tenantId, string $agentType, int|string $agentId): bool;

    /** A single ticket — its agents, requester/subject or participants. */
    public function forTicket(Authenticatable $user, int|string $ticketId): bool;
}
