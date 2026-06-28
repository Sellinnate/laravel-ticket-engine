<?php

declare(strict_types=1);

namespace Selli\Ticketing\Commands;

use Illuminate\Console\Command;
use Selli\Ticketing\Gdpr\ApplyRetention;

/**
 * Applies the configured GDPR retention rules (ticketing.gdpr.retention).
 * Schedule it daily to anonymise/delete old closed tickets automatically.
 */
class PruneCommand extends Command
{
    protected $signature = 'ticketing:prune';

    protected $description = 'Apply the configured ticket retention rules (anonymise or delete old closed tickets).';

    public function handle(ApplyRetention $retention): int
    {
        $results = $retention->handle();

        if ($results === []) {
            $this->info('No retention rules configured.');

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            $this->line(sprintf('  %s [type: %s] — %d ticket(s)', $result['action'], $result['type'], $result['count']));
        }

        return self::SUCCESS;
    }
}
