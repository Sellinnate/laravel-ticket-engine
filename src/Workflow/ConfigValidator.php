<?php

declare(strict_types=1);

namespace Selli\Ticketing\Workflow;

use Selli\Ticketing\Contracts\TransitionGuard;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;

/**
 * Validates the workflow configuration at boot so a typo (a transition that
 * points at a non-existent state, a type that references an unknown workflow)
 * fails fast rather than at runtime.
 */
class ConfigValidator
{
    public function validate(): void
    {
        /** @var array<string, array<string, mixed>> $workflows */
        $workflows = config('ticketing.workflow.workflows', []);

        foreach ($workflows as $key => $workflow) {
            // Guard the entry SHAPE before iterating: a published override that
            // sets a workflow to a scalar would otherwise raise a TypeError
            // instead of a clear InvalidConfigurationException.
            if (! is_array($workflow)) {
                throw new InvalidConfigurationException("Workflow [{$key}] must be an array.");
            }

            $this->validateWorkflow((string) $key, $workflow);
        }

        $this->validateTypes($workflows);
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    protected function validateWorkflow(string $key, array $workflow): void
    {
        $states = $workflow['states'] ?? null;

        if (! is_array($states) || $states === []) {
            throw new InvalidConfigurationException("Workflow [{$key}] must declare a non-empty 'states' list.");
        }

        $initial = $workflow['initial'] ?? null;

        if (! is_string($initial) || ! in_array($initial, $states, true)) {
            throw new InvalidConfigurationException("Workflow [{$key}] has an invalid or missing initial state.");
        }

        $transitions = $workflow['transitions'] ?? [];

        if (! is_array($transitions)) {
            throw new InvalidConfigurationException("Workflow [{$key}] transitions must be an array.");
        }

        foreach ($transitions as $name => $transition) {
            $this->validateTransition($key, (string) $name, $transition, $states);
        }

        foreach ((array) ($workflow['terminal'] ?? []) as $terminal) {
            if (! in_array($terminal, $states, true)) {
                throw new InvalidConfigurationException("Workflow [{$key}] terminal state [{$terminal}] is not a declared state.");
            }
        }

        foreach ((array) ($workflow['semantics'] ?? []) as $semantic => $mapped) {
            foreach ((array) $mapped as $state) {
                if (! in_array($state, $states, true)) {
                    throw new InvalidConfigurationException("Workflow [{$key}] semantic [{$semantic}] maps unknown state [{$state}].");
                }
            }
        }
    }

    /**
     * @param  list<string>  $states
     */
    protected function validateTransition(string $workflow, string $name, mixed $transition, array $states): void
    {
        if (! is_array($transition) || ! isset($transition['from'], $transition['to'])) {
            throw new InvalidConfigurationException("Transition [{$workflow}.{$name}] must declare 'from' and 'to'.");
        }

        $froms = (array) $transition['from'];

        foreach ($froms as $from) {
            if (! in_array($from, $states, true)) {
                throw new InvalidConfigurationException("Transition [{$workflow}.{$name}] 'from' state [{$from}] is not declared.");
            }
        }

        if (! in_array($transition['to'], $states, true)) {
            throw new InvalidConfigurationException("Transition [{$workflow}.{$name}] 'to' state [{$transition['to']}] is not declared.");
        }

        foreach ((array) ($transition['guard'] ?? []) as $guard) {
            if (! is_string($guard)) {
                throw new InvalidConfigurationException("Transition [{$workflow}.{$name}] guard must be a class-string.");
            }

            if (! class_exists($guard)) {
                throw new InvalidConfigurationException("Transition [{$workflow}.{$name}] guard [{$guard}] class does not exist.");
            }

            if (! is_a($guard, TransitionGuard::class, true)) {
                throw new InvalidConfigurationException(
                    "Transition [{$workflow}.{$name}] guard [{$guard}] must implement ".TransitionGuard::class.'.'
                );
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $workflows
     */
    protected function validateTypes(array $workflows): void
    {
        /** @var array<string, array{workflow?: string}> $types */
        $types = config('ticketing.types', []);

        foreach ($types as $key => $definition) {
            if (! is_array($definition)) {
                throw new InvalidConfigurationException("Ticket type [{$key}] must be an array.");
            }

            $workflow = $definition['workflow'] ?? 'default';

            if (! array_key_exists($workflow, $workflows)) {
                throw new InvalidConfigurationException("Ticket type [{$key}] references unknown workflow [{$workflow}].");
            }
        }
    }
}
