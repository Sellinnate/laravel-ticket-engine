<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Selli\Ticketing\Contracts\TenantScoped;

/**
 * Global scope that filters every read to the current tenant.
 *
 * Safety properties enforced here:
 *  - With a tenant resolved, only that tenant's rows (plus shared rows, if
 *    enabled) are visible.
 *  - With NO tenant resolved but tenancy enabled, only shared (null-tenant)
 *    rows are visible — never another tenant's data. This makes a missing
 *    context fail closed, not open.
 *
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->enabled()) {
            return;
        }

        /** @var Model&TenantScoped $model */
        $column = $model->getQualifiedTenantColumn();
        $tenant = $context->current();

        if ($tenant === null) {
            $builder->whereNull($column);

            return;
        }

        if ($context->allowsShared()) {
            $builder->where(function (Builder $query) use ($column, $tenant): void {
                $query->where($column, $tenant)->orWhereNull($column);
            });

            return;
        }

        $builder->where($column, $tenant);
    }
}
