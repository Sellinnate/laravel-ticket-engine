<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

/**
 * Implemented (via the BelongsToTenant trait) by every tenant-scoped model.
 * Exists primarily to give the tenant scope and boot hooks a resolvable type.
 */
interface TenantScoped
{
    public function getTenantColumn(): string;

    public function getQualifiedTenantColumn(): string;
}
