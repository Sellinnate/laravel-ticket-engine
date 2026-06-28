<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Resolves the current tenant identifier for scoping reads and writes.
 *
 * The default implementation derives the tenant from the authenticated user.
 * Host applications that already run a tenancy package can bind their own
 * resolver to delegate to that package's notion of "current tenant" — the
 * ticketing engine then inherits the context with no duplication.
 *
 * There is no implicit, uncontrollable global state: for CLI, queues and the
 * email channel the tenant can always be set explicitly via
 * {@see TenantContext}.
 */
interface TenantResolver
{
    /**
     * The identifier of the current tenant, or null when none is active
     * (tenancy disabled, or a deliberately unscoped context).
     */
    public function resolve(): int|string|null;
}
