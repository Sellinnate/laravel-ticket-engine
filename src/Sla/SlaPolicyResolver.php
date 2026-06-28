<?php

declare(strict_types=1);

namespace Selli\Ticketing\Sla;

use Selli\Ticketing\Models\SlaPolicy;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;

/**
 * Resolves the most specific active SLA policy for a ticket: an exact
 * type+priority match wins over type-only, which wins over priority-only, which
 * wins over the catch-all (both null).
 */
class SlaPolicyResolver
{
    public function resolve(Ticket $ticket): ?SlaPolicy
    {
        $model = Ticketing::slaPolicyModel();
        $priority = $ticket->priority->value;

        $candidates = $model::query()
            ->where('is_active', true)
            ->where(function ($query) use ($ticket): void {
                $query->whereNull('ticket_type_id')->orWhere('ticket_type_id', $ticket->ticket_type_id);
            })
            ->where(function ($query) use ($priority): void {
                $query->whereNull('priority')->orWhere('priority', $priority);
            })
            ->get();

        // Most specific wins; ties break deterministically on the policy key so
        // the choice is stable regardless of database row order.
        return $candidates
            ->sort(function (SlaPolicy $a, SlaPolicy $b): int {
                return $this->specificity($b) <=> $this->specificity($a)
                    ?: ($b->getKey() <=> $a->getKey());
            })
            ->first();
    }

    protected function specificity(SlaPolicy $policy): int
    {
        return ($policy->ticket_type_id !== null ? 2 : 0) + ($policy->priority !== null ? 1 : 0);
    }
}
