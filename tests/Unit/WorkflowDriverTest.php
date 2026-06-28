<?php

declare(strict_types=1);

use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Exceptions\UnknownTransitionException;
use Selli\Ticketing\Workflow\ConfigWorkflowDriver;
use Selli\Ticketing\Workflow\Guards\RequireResolutionNote;

beforeEach(function (): void {
    $this->driver = new ConfigWorkflowDriver;
});

it('reports the initial state and declared states', function (): void {
    expect($this->driver->initialState('incident'))->toBe('new')
        ->and($this->driver->states('incident'))->toContain('new', 'triaged', 'resolved', 'closed');
});

it('resolves a transition with its from/to/guards', function (): void {
    $transition = $this->driver->resolveTransition('incident', 'resolve');

    expect($transition->to)->toBe('resolved')
        ->and($transition->allowsFrom('in_progress'))->toBeTrue()
        ->and($transition->guards)->toContain(RequireResolutionNote::class);
});

it('knows whether a transition can apply from a state', function (): void {
    expect($this->driver->canApply('incident', 'new', 'triage'))->toBeTrue()
        ->and($this->driver->canApply('incident', 'new', 'close'))->toBeFalse()
        ->and($this->driver->canApply('incident', 'new', 'nope'))->toBeFalse();
});

it('maps states to system semantics and terminals', function (): void {
    expect($this->driver->isTerminal('incident', 'closed'))->toBeTrue()
        ->and($this->driver->isTerminal('incident', 'new'))->toBeFalse()
        ->and($this->driver->matchesSemantic('incident', 'pending_customer', 'paused'))->toBeTrue()
        ->and($this->driver->matchesSemantic('incident', 'resolved', 'closed'))->toBeTrue()
        ->and($this->driver->statesForSemantic('incident', 'open'))->toContain('new');
});

it('throws for an unknown transition', function (): void {
    $this->driver->resolveTransition('incident', 'ghost');
})->throws(UnknownTransitionException::class);

it('throws for an undefined workflow', function (): void {
    $this->driver->initialState('does-not-exist');
})->throws(InvalidConfigurationException::class);
