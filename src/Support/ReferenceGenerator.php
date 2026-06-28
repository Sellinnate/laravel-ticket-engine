<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Illuminate\Database\Eloquent\Builder;
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
        $scope = strtoupper($typeKey).'-'.$year;
        $tenant = $this->tenant->current();
        $column = $this->tenant->column();

        return DB::transaction(function () use ($scope, $tenant, $column): int {
            $row = $this->lockedRow($scope, $tenant, $column);

            if ($row === null) {
                // Create the counter row race-safely, then re-acquire the lock.
                TicketSequence::query()->withoutTenancy()->createOrFirst(
                    [$column => $tenant, 'scope' => $scope],
                    ['next_value' => 0],
                );

                $row = $this->lockedRow($scope, $tenant, $column);
            }

            $next = (int) $row->next_value + 1;
            $row->forceFill(['next_value' => $next])->save();

            return $next;
        });
    }

    /**
     * Fetch the sequence row for an explicit tenant under a write lock.
     */
    protected function lockedRow(string $scope, int|string|null $tenant, string $column): ?TicketSequence
    {
        return TicketSequence::query()
            ->withoutTenancy()
            ->where('scope', $scope)
            ->when(
                $tenant === null,
                fn (Builder $query): Builder => $query->whereNull($column),
                fn (Builder $query): Builder => $query->where($column, $tenant),
            )
            ->lockForUpdate()
            ->first();
    }
}
