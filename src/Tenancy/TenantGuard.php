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
 */
class TenantGuard
{
    public function belongsToTicketTenant(Model $model, Ticket $ticket): bool
    {
        $column = $ticket->getTenantColumn();

        // A model whose table has no tenant column is not scoped by us. Detect
        // this on the model's own attributes (we always load full rows) — a model
        // that carries the column but with a null value is treated as shared.
        if (! array_key_exists($column, $model->getAttributes())) {
            return true;
        }

        $modelTenant = $model->getAttribute($column);
        $ticketTenant = $ticket->getAttribute($column);
        $allowShared = config('ticketing.tenancy.allow_shared', true) !== false;

        // A shared (null-tenant) model is allowed only when sharing is enabled.
        if ($modelTenant === null) {
            return $allowShared;
        }

        // Otherwise the tenants must match. Compare as strings to avoid loose
        // null/0/numeric-string coercion between int and string keys.
        return (string) $modelTenant === (string) $ticketTenant;
    }
}
