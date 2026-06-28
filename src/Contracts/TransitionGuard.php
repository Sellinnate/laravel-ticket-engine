<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Selli\Ticketing\Workflow\TransitionContext;

/**
 * A condition that must hold for a transition to be allowed. A failed guard
 * produces a typed domain error, not a generic exception.
 */
interface TransitionGuard
{
    public function allows(TransitionContext $context): bool;

    /**
     * The human readable reason shown when the guard denies the transition.
     */
    public function deniedMessage(): string;
}
