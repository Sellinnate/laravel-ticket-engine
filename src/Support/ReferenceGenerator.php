<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Models\TicketSequence;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Generates human-friendly per-tenant ticket references such as
 * "INC-2026-00042" from the configured format.
 *
 * Sequence values are allocated atomically from the `ticket_sequences` table
 * under a row lock, so concurrent opens always receive distinct, monotonic
 * numbers — independent of soft-deleted rows or an engine's NULL-uniqueness
 * behaviour. This avoids the pitfalls of deriving the next number from a row
 * count.
 */
class ReferenceGenerator
{
    public function __construct(protected TenantContext $tenant) {}

    public function generate(string $typeKey, ?int $sequence = null): string
    {
        $format = (string) config('ticketing.reference.format', '{type}-{year}-{seq}');
        $padding = (int) config('ticketing.reference.sequence_padding', 5);
        $year = (int) date('Y');

        $sequence ??= $this->nextSequence($typeKey, $year);

        return strtr($format, [
            '{type}' => strtoupper($typeKey),
            '{year}' => (string) $year,
            '{seq}' => str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * Atomically allocate the next sequence value for the current tenant +
     * type + year.
     */
    public function nextSequence(string $typeKey, int $year): int
    {
        $tenant = $this->tenant->current();
        $column = $this->tenant->column();

        // Encode the tenant into the (non-null) scope key so the unique index
        // never relies on NULL grouping — a null tenant maps to "shared".
        $tenantKey = $tenant === null ? 'shared' : (string) $tenant;
        $scope = $tenantKey.':'.strtoupper($typeKey).'-'.$year;

        return DB::transaction(function () use ($scope, $tenant, $column): int {
            $row = $this->lockedRow($scope);

            if ($row === null) {
                // Create the counter row race-safely, then re-acquire the lock.
                TicketSequence::query()->withoutTenancy()->createOrFirst(
                    ['scope' => $scope],
                    [$column => $tenant, 'next_value' => 0],
                );

                $row = $this->lockedRow($scope);
            }

            $next = (int) $row->next_value + 1;
            $row->forceFill(['next_value' => $next])->save();

            return $next;
        });
    }

    /**
     * Fetch the sequence row for a scope under a write lock.
     */
    protected function lockedRow(string $scope): ?TicketSequence
    {
        return TicketSequence::query()
            ->withoutTenancy()
            ->where('scope', $scope)
            ->lockForUpdate()
            ->first();
    }
}
