<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;

/**
 * A host user scoped by the host's own tenancy (not the package's), like a
 * stancl/tenancy or spatie/laravel-multitenancy app: it only loads while the
 * host's current tenant is its own.
 */
class HostScopedUser extends TestUser
{
    /** The host's notion of the current tenant. */
    public static int|string|null $tenant = null;

    protected static function booted(): void
    {
        static::addGlobalScope('host-tenant', fn (Builder $query) => $query->where('tenant_id', static::$tenant));
    }
}
