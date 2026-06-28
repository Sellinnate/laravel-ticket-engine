<?php

declare(strict_types=1);

namespace Selli\Ticketing\Workflow;

use Selli\Ticketing\Contracts\TransitionGuard;
use Selli\Ticketing\Contracts\WorkflowDriver;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Exceptions\UnknownTransitionException;

/**
 * Default workflow driver. States, transitions and the mapping of custom states
 * onto system semantics are declared in configuration per workflow key.
 */
class ConfigWorkflowDriver implements WorkflowDriver
{
    public function initialState(string $workflow): string
    {
        $initial = $this->workflow($workflow)['initial'] ?? null;

        if (! is_string($initial) || $initial === '') {
            throw new InvalidConfigurationException("Workflow [{$workflow}] has no initial state.");
        }

        return $initial;
    }

    public function states(string $workflow): array
    {
        /** @var list<string> $states */
        $states = array_values((array) ($this->workflow($workflow)['states'] ?? []));

        return $states;
    }

    public function hasTransition(string $workflow, string $transition): bool
    {
        return isset($this->workflow($workflow)['transitions'][$transition]);
    }

    public function resolveTransition(string $workflow, string $transition): Transition
    {
        $transitions = (array) ($this->workflow($workflow)['transitions'] ?? []);

        if (! isset($transitions[$transition])) {
            throw UnknownTransitionException::make($workflow, $transition);
        }

        /** @var array{from: string|list<string>, to: string, guard?: class-string<TransitionGuard>|list<class-string<TransitionGuard>>} $definition */
        $definition = $transitions[$transition];

        /** @var list<string> $from */
        $from = array_values((array) $definition['from']);

        /** @var list<class-string<TransitionGuard>> $guards */
        $guards = array_values((array) ($definition['guard'] ?? []));

        return new Transition(
            name: $transition,
            from: $from,
            to: $definition['to'],
            guards: $guards,
        );
    }

    public function canApply(string $workflow, string $from, string $transition): bool
    {
        if (! $this->hasTransition($workflow, $transition)) {
            return false;
        }

        return $this->resolveTransition($workflow, $transition)->allowsFrom($from);
    }

    public function isTerminal(string $workflow, string $state): bool
    {
        return in_array($state, (array) ($this->workflow($workflow)['terminal'] ?? []), true);
    }

    public function matchesSemantic(string $workflow, string $state, string $semantic): bool
    {
        return in_array($state, $this->statesForSemantic($workflow, $semantic), true);
    }

    public function statesForSemantic(string $workflow, string $semantic): array
    {
        /** @var list<string> $states */
        $states = array_values((array) ($this->workflow($workflow)['semantics'][$semantic] ?? []));

        return $states;
    }

    /**
     * @return array<string, mixed>
     */
    protected function workflow(string $workflow): array
    {
        $config = config("ticketing.workflow.workflows.{$workflow}");

        if (! is_array($config)) {
            throw new InvalidConfigurationException("Workflow [{$workflow}] is not defined.");
        }

        return $config;
    }
}
