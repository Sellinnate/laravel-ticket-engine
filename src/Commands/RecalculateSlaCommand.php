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
    protected $signature = 'ticketing:recalculate-sla {--chunk=200 : Tickets processed per chunk}';

    protected $description = 'Recompute SLA deadlines for tickets with running clocks.';

    public function handle(SlaManager $sla, TenantContext $tenant): int
    {
        $clockModel = Ticketing::slaClockModel();
        $ticketModel = Ticketing::ticketModel();
        $count = 0;

        $clockModel::query()
            ->withoutTenancy()
            ->whereNull('completed_at')
            ->select(['ticket_id', $tenant->column()])
            ->distinct()
            ->orderBy('ticket_id')
            ->chunk((int) $this->option('chunk'), function ($rows) use ($sla, $tenant, $ticketModel, &$count): void {
                foreach ($rows as $row) {
                    $tenant->forTenant($row->getAttribute($tenant->column()), function () use ($sla, $ticketModel, $row, &$count): void {
                        $ticket = $ticketModel::query()->withoutTenancy()->find($row->ticket_id);

                        if ($ticket !== null) {
                            $sla->recalculate($ticket);
                            $count++;
                        }
                    });
                }
            });

        $this->info("Recalculated SLA for {$count} ticket(s).");

        return self::SUCCESS;
    }
}
