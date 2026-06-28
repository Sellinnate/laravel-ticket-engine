<?php

declare(strict_types=1);

namespace Selli\Ticketing\Workflow\Guards;

use Selli\Ticketing\Contracts\TransitionGuard;
use Selli\Ticketing\Workflow\TransitionContext;

/**
 * Example guard: a ticket cannot be resolved without a resolution note.
 *
 * Reference it from a workflow transition via the 'guard' key.
 */
class RequireResolutionNote implements TransitionGuard
{
    public function allows(TransitionContext $context): bool
    {
        return is_string($context->note) && trim($context->note) !== '';
    }

    public function deniedMessage(): string
    {
        return 'A resolution note is required to resolve this ticket.';
    }
}
