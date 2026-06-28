<?php

declare(strict_types=1);

namespace Selli\Ticketing\Commands;

use Illuminate\Console\Command;
use Selli\Ticketing\Sla\SlaManager;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Recomputes due_at for every running SLA clock using the current policies and
 * calendars — useful after editing an SLA policy or a working calendar.
 */
class RecalculateSlaCommand extends Command
{
    protected $signature = 'ticketing:recalculate-sla {--chunk=200 : Rows fetched per batch}';

    protected $description = 'Recompute SLA deadlines for tickets with running clocks.';

    public function handle(SlaManager $sla, TenantContext $tenant): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $clockModel = Ticketing::slaClockModel();
        $ticketModel = Ticketing::ticketModel();
        $column = $tenant->column();

        $seen = [];
        $count = 0;

        // Cursor by primary key so completing clocks during recalculate cannot
        // shift an offset and skip tickets. Each ticket is recalculated once.
        $clockModel::query()
            ->withoutTenancy()
            ->whereNull('completed_at')
            ->orderBy((new $clockModel)->getKeyName())
            ->lazyById($chunk)
            ->each(function ($clock) use ($sla, $tenant, $ticketModel, $column, &$seen, &$count): void {
                $key = $clock->getAttribute($column).'|'.$clock->ticket_id;

                if (isset($seen[$key])) {
                    return;
                }

                $seen[$key] = true;

                $tenant->forTenant($clock->getAttribute($column), function () use ($sla, $ticketModel, $clock, &$count): void {
                    $ticket = $ticketModel::query()->withoutTenancy()->find($clock->ticket_id);

                    if ($ticket !== null) {
                        $sla->recalculate($ticket);
                        $count++;
                    }
                });
            });

        $this->info("Recalculated SLA for {$count} ticket(s).");

        return self::SUCCESS;
    }
}
