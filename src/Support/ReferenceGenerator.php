<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Generates human-friendly per-tenant ticket references such as
 * "INC-2026-00042" from the configured format. Collisions are resolved by the
 * caller (OpenTicket) which retries on a unique-constraint violation.
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
     * Next sequence number for the current tenant + type + year.
     */
    public function nextSequence(string $typeKey, int $year): int
    {
        $model = Ticketing::ticketModel();
        $prefix = strtoupper($typeKey).'-'.$year.'-';

        $count = $model::query()
            ->where('reference', 'like', $prefix.'%')
            ->count();

        return $count + 1;
    }
}
