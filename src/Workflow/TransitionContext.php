<?php

declare(strict_types=1);

namespace Selli\Ticketing\Workflow;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\Ticket;

/**
 * The context a guard evaluates a transition against.
 */
final readonly class TransitionContext
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public Ticket $ticket,
        public string $transition,
        public string $from,
        public string $to,
        public ?Model $actor = null,
        public ?string $note = null,
        public array $params = [],
    ) {}
}
