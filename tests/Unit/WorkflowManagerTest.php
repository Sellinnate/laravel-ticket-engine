<?php

declare(strict_types=1);

use Selli\Ticketing\Contracts\WorkflowDriver;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Workflow\ConfigWorkflowDriver;
use Selli\Ticketing\Workflow\Transition;
use Selli\Ticketing\Workflow\WorkflowManager;

it('resolves the default config driver', function (): void {
    $manager = app(WorkflowManager::class);

    expect($manager->driver())->toBeInstanceOf(ConfigWorkflowDriver::class);
});

it('lets a custom driver be registered', function (): void {
    $manager = app(WorkflowManager::class);

    $fake = new class implements WorkflowDriver
    {
        public function initialState(string $workflow): string
        {
            return 'start';
        }

        public function states(string $workflow): array
        {
            return ['start'];
        }

        public function hasTransition(string $workflow, string $transition): bool
        {
            return false;
        }

        public function resolveTransition(string $workflow, string $transition): Transition
        {
            return new Transition('x', ['start'], 'start');
        }

        public function canApply(string $workflow, string $from, string $transition): bool
        {
            return false;
        }

        public function isTerminal(string $workflow, string $state): bool
        {
            return false;
        }

        public function matchesSemantic(string $workflow, string $state, string $semantic): bool
        {
            return false;
        }

        public function statesForSemantic(string $workflow, string $semantic): array
        {
            return [];
        }
    };

    $manager->extend('fake', fn () => $fake);

    expect($manager->driver('fake'))->toBe($fake);
});

it('throws for an unknown driver', function (): void {
    app(WorkflowManager::class)->driver('does-not-exist');
})->throws(InvalidConfigurationException::class);
