<?php

declare(strict_types=1);

namespace Selli\Ticketing\Exceptions;

/**
 * Raised when a tenant-scoped model is written while tenancy is enabled, no
 * tenant is resolved, and `tenancy.require_tenant_for_writes` is on — so a
 * forgotten context fails the write instead of silently creating a shared row.
 */
class MissingTenantException extends TicketingException
{
    public static function forModel(string $model): self
    {
        return new self(
            "Refusing to write [{$model}] without a resolved tenant. Set the current tenant "
            .'(e.g. via TenantContext::forTenant or an explicit null for shared records), or disable '
            .'ticketing.tenancy.require_tenant_for_writes.'
        );
    }
}
