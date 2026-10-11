<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\Ticket;

/**
 * Decides whether a related model (a team, an agent, …) may serve a ticket's
 * tenant. Models that do not carry the configured tenant column are not scoped
 * by the package (a single shared pool); models that do must match the ticket's
 * tenant, or be shared (null tenant) when allow_shared is enabled.
 *
 * @internal
 */
class TenantGuard
{
    public function belongsToTicketTenant(Model $model, Ticket $ticket): bool
    {
        return $this->belongsToTenantOf($model, $ticket, $ticket->getTenantColumn());
    }

    /**
     * Whether a model belongs to the current tenant (same rules as
     * belongsToTenantOf(), against the resolved tenant rather than a row).
     */
    public function belongsToCurrentTenant(Model $model): bool
    {
        $context = app(TenantContext::class);

        // Single-tenant: there is no tenant to belong to.
        if (! $context->enabled()) {
            return true;
        }

        $column = $context->column();

        if (! array_key_exists($column, $model->getAttributes())) {
            return true;
        }

        $modelTenant = $model->getAttribute($column);

        if ($modelTenant === null) {
            return config('ticketing.tenancy.allow_shared', true) !== false;
        }

        $current = $context->current();

        return $current !== null && (string) $modelTenant === (string) $current;
    }

    /**
     * The same check against any tenant-scoped owner (an article, a widget
     * key, …): the model must carry no tenant column, be shared (when sharing
     * is on) or belong to the owner's tenant.
     */
    public function belongsToTenantOf(Model $model, Model $owner, ?string $column = null): bool
    {
        // Single-tenant: a host model may still carry a tenant value, but
        // there is nothing to separate (tickets have no tenant either).
        if (! app(TenantContext::class)->enabled()) {
            return true;
        }

        $column ??= (string) config('ticketing.tenancy.column', 'tenant_id');

        // A model whose table has no tenant column is not scoped by us. Detect
        // this on the model's own attributes (we always load full rows) — a model
        // that carries the column but with a null value is treated as shared.
        if (! array_key_exists($column, $model->getAttributes())) {
            return true;
        }

        $modelTenant = $model->getAttribute($column);
        $ownerTenant = $owner->getAttribute($column);
        $allowShared = config('ticketing.tenancy.allow_shared', true) !== false;

        // A shared (null-tenant) model is allowed only when sharing is enabled.
        if ($modelTenant === null) {
            return $allowShared;
        }

        // Otherwise the tenants must match. Compare as strings to avoid loose
        // null/0/numeric-string coercion between int and string keys.
        return (string) $modelTenant === (string) $ownerTenant;
    }
}
