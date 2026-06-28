<?php

declare(strict_types=1);

namespace Selli\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted after a requester's denormalised PII has been scrubbed from the
 * package's tables. The host listens to also anonymise its own requester model.
 */
class RequesterAnonymized implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Model $requester,
        public int $tickets,
    ) {}
}
