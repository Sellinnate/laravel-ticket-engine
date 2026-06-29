<?php

declare(strict_types=1);

namespace Selli\Ticketing\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\TenantScoped;
use Selli\Ticketing\Exceptions\MissingTenantException;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Tenancy\TenantScope;

/**
 * Applied to every package model. Adds the tenant global scope on reads and an
 * automatic tenant assignment on writes, so a developer never has to remember
 * to filter — it is structural.
 *
 * @method static Builder<static> withoutTenancy()
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if (! $context->enabled()) {
                return;
            }

            /** @var Model&TenantScoped $model */
            $column = $model->getTenantColumn();

            // An EXPLICIT tenant value (including null for a shared row) is always
            // honoured: setting it deliberately is a first-class operation the
            // engine itself relies on — child rows (messages, participants, …)
            // inherit their PARENT ticket's tenant via tenantAttributes(), which
            // legitimately differs from the ambient context in a queue/CLI flow.
            // The cross-tenant defence is the read scope (which fails closed) plus
            // the fact that the package's own write paths never expose the tenant
            // column to user input; a host that hand-writes a tenant id owns that
            // choice, exactly as it owns any other column it sets.
            if (array_key_exists($column, $model->getAttributes())) {
                return;
            }

            $current = $context->current();

            // Optionally fail closed on writes: a missing tenant context would
            // otherwise create a null (shared) row visible to every tenant.
            if ($current === null && config('ticketing.tenancy.require_tenant_for_writes', false)) {
                throw MissingTenantException::forModel($model::class);
            }

            $model->setAttribute($column, $current);
        });
    }

    /**
     * The tenant column for this model. Override per-model via a $tenantColumn
     * property; otherwise the package-wide configured column is used.
     */
    public function getTenantColumn(): string
    {
        if (property_exists($this, 'tenantColumn') && is_string($this->tenantColumn)) {
            return $this->tenantColumn;
        }

        return app(TenantContext::class)->column();
    }

    public function getQualifiedTenantColumn(): string
    {
        return $this->qualifyColumn($this->getTenantColumn());
    }

    /**
     * Attributes that propagate this record's tenant onto a child record.
     *
     * Child rows of a ticket (messages, participants, activities, …) must
     * inherit the ticket's tenant explicitly rather than rely on ambient
     * context — otherwise a queue/CLI write with no resolved tenant would
     * persist a null (shared) row, leaking across tenants.
     *
     * @return array<string, int|string|null>
     */
    public function tenantAttributes(): array
    {
        $column = $this->getTenantColumn();

        return [$column => $this->getAttribute($column)];
    }

    /**
     * Escape hatch: query without the tenant scope. Use deliberately.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutTenancy(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
