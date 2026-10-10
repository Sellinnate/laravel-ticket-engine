<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Models\AiTurn;

/**
 * The AI driver failed (provider outage, timeout, …). The ticket or
 * conversation is escalated to humans with reason "ai_error".
 */
class AiTurnFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public AiTurn $turn,
        public string $error,
    ) {}
}
