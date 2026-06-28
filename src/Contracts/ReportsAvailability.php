<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

/**
 * Optional contract an agent model can implement so routing skips agents who are
 * currently unavailable (offline, on holiday, …). Agents that do not implement
 * it are always considered available.
 */
interface ReportsAvailability
{
    public function isAvailableForTickets(): bool;
}
