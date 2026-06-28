<?php

declare(strict_types=1);

namespace Selli\Ticketing\Sla;

use Selli\Ticketing\Models\SlaPolicy;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;

/**
 * Resolves the most specific active SLA policy for a ticket: an exact
 * type+priority match wins over type-only, which wins over priority-only, which
 * wins over the catch-all. At equal specificity a tenant-owned policy beats a
 * shared one, and remaining ties break deterministically on the key.
 */
class SlaPolicyResolver
{
    public function resolve(Ticket $ticket): ?SlaPolicy
    {
        $model = Ticketing::slaPolicyModel();
        $priority = $ticket->priority->value;
        $tenantColumn = $ticket->getTenantColumn();
        $tenantValue = $ticket->getAttribute($tenantColumn);
        $allowShared = config('ticketing.tenancy.allow_shared', true) !== false;

        // Resolve against the ticket's own tenant without relying on ambient
        // scope; include shared null-tenant policies only when allow_shared is on.
        $candidates = $model::query()
            ->withoutTenancy()
            ->where('is_active', true)
            ->where(function ($query) use ($tenantColumn, $tenantValue, $allowShared): void {
                $query->where($tenantColumn, $tenantValue);

                if ($allowShared) {
                    $query->orWhereNull($tenantColumn);
                }
            })
            ->where(function ($query) use ($ticket): void {
                $query->whereNull('ticket_type_id')->orWhere('ticket_type_id', $ticket->ticket_type_id);
            })
            ->where(function ($query) use ($priority): void {
                $query->whereNull('priority')->orWhere('priority', $priority);
            })
            ->get();

        return $candidates
            ->sort(function (SlaPolicy $a, SlaPolicy $b) use ($tenantColumn): int {
                return $this->rank($b, $tenantColumn) <=> $this->rank($a, $tenantColumn)
                    ?: ($b->getKey() <=> $a->getKey());
            })
            ->first();
    }

    /**
     * Specificity dominates; a tenant-owned policy outranks a shared one at the
     * same specificity.
     */
    protected function rank(SlaPolicy $policy, string $tenantColumn): int
    {
        $specificity = ($policy->ticket_type_id !== null ? 2 : 0) + ($policy->priority !== null ? 1 : 0);
        $owned = $policy->getAttribute($tenantColumn) !== null ? 1 : 0;

        return $specificity * 10 + $owned;
    }
}
