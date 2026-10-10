<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Models\AiTurn;

/**
 * An AI turn finished and its token usage and cost were recorded on the turn
 * (input_tokens, output_tokens, cost, currency, model, latency_ms) — the hook
 * for budgets, alerts and billing.
 */
class AiUsageRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public AiTurn $turn) {}
}
