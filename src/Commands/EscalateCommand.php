<?php

declare(strict_types=1);

namespace Selli\Ticketing\Commands;

use Illuminate\Console\Command;
use Selli\Ticketing\Sla\SlaManager;

/**
 * Sweeps SLA clocks for thresholds and breaches. Schedule it (e.g. every
 * minute) to drive escalations.
 */
class EscalateCommand extends Command
{
    protected $signature = 'ticketing:escalate {--threshold= : Warning threshold percent (defaults to config)} {--chunk=200 : Rows processed per chunk}';

    protected $description = 'Sweep SLA clocks and emit threshold/breach events.';

    public function handle(SlaManager $sla): int
    {
        $threshold = $this->option('threshold') !== null
            ? (int) $this->option('threshold')
            : (int) config('ticketing.sla.default_threshold_percent', 75);
        $chunk = (int) $this->option('chunk');

        $sla->sweep($threshold, $chunk);

        $this->info('SLA sweep complete.');

        return self::SUCCESS;
    }
}
