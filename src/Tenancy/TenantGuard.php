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

        if (! array_key_exists($column, $model->getAttributes())) {
            return true;
        }

        $modelTenant = $model->getAttribute($column);
        $ticketTenant = $ticket->getAttribute($column);
        $allowShared = config('ticketing.tenancy.allow_shared', true) !== false;

        return $modelTenant == $ticketTenant || ($modelTenant === null && $allowShared);
    }
}
