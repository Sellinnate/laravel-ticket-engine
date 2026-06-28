<?php

declare(strict_types=1);

namespace Selli\Ticketing\Data;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\Ticket;

/**
 * Typed input for applying a workflow transition.
 */
final readonly class TransitionData
{
    /**
     * @param  array<string, mixed>  $params  extra data made available to guards
     */
    public function __construct(
        public Ticket $ticket,
        public string $transition,
        public ?Model $actor = null,
        public ?string $note = null,
        public array $params = [],
    ) {}
}
