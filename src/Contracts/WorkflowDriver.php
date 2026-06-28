<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Selli\Ticketing\Workflow\Transition;

/**
 * Resolves states & transitions for a workflow. The rest of the package (SLA,
 * routing, events) talks to this interface, never to a concrete driver, so the
 * config-driven and state-class implementations are interchangeable.
 */
interface WorkflowDriver
{
    public function initialState(string $workflow): string;

    /**
     * @return list<string>
     */
    public function states(string $workflow): array;

    public function hasTransition(string $workflow, string $transition): bool;

    public function resolveTransition(string $workflow, string $transition): Transition;

    /**
     * Whether the named transition may be applied from the given state.
     */
    public function canApply(string $workflow, string $from, string $transition): bool;

    public function isTerminal(string $workflow, string $state): bool;

    /**
     * Whether a custom state maps to one of the system semantics
     * (open / closed / paused).
     */
    public function matchesSemantic(string $workflow, string $state, string $semantic): bool;

    /**
     * @return list<string>
     */
    public function statesForSemantic(string $workflow, string $semantic): array;
}
