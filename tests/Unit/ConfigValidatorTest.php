<?php

declare(strict_types=1);

use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Workflow\ConfigValidator;

it('passes for the default configuration', function (): void {
    app(ConfigValidator::class)->validate();
})->throwsNoExceptions();

it('rejects a workflow whose initial state is not declared', function (): void {
    config()->set('ticketing.workflow.workflows.broken', [
        'initial' => 'ghost',
        'states' => ['open', 'closed'],
        'transitions' => [],
    ]);

    app(ConfigValidator::class)->validate();
})->throws(InvalidConfigurationException::class);

it('rejects a transition pointing at an undeclared state', function (): void {
    config()->set('ticketing.workflow.workflows.broken', [
        'initial' => 'open',
        'states' => ['open', 'closed'],
        'transitions' => [
            'weird' => ['from' => ['open'], 'to' => 'nowhere'],
        ],
    ]);

    app(ConfigValidator::class)->validate();
})->throws(InvalidConfigurationException::class);

it('rejects a type referencing an unknown workflow', function (): void {
    config()->set('ticketing.types.weird', ['name' => 'Weird', 'workflow' => 'ghost-flow']);

    app(ConfigValidator::class)->validate();
})->throws(InvalidConfigurationException::class);

it('rejects a workflow with empty states', function (): void {
    config()->set('ticketing.workflow.workflows.broken', ['initial' => 'x', 'states' => []]);

    app(ConfigValidator::class)->validate();
})->throws(InvalidConfigurationException::class);

it('rejects a transition missing from/to keys', function (): void {
    config()->set('ticketing.workflow.workflows.broken', [
        'initial' => 'open',
        'states' => ['open'],
        'transitions' => ['bad' => ['from' => ['open']]],
    ]);

    app(ConfigValidator::class)->validate();
})->throws(InvalidConfigurationException::class);

it('rejects a transition with an undeclared from state', function (): void {
    config()->set('ticketing.workflow.workflows.broken', [
        'initial' => 'open',
        'states' => ['open'],
        'transitions' => ['bad' => ['from' => ['ghost'], 'to' => 'open']],
    ]);

    app(ConfigValidator::class)->validate();
})->throws(InvalidConfigurationException::class);

it('rejects a guard that does not implement the contract', function (): void {
    config()->set('ticketing.workflow.workflows.broken', [
        'initial' => 'open',
        'states' => ['open', 'closed'],
        'transitions' => [
            'close' => ['from' => ['open'], 'to' => 'closed', 'guard' => stdClass::class],
        ],
    ]);

    app(ConfigValidator::class)->validate();
})->throws(InvalidConfigurationException::class);

it('rejects a guard class that does not exist', function (): void {
    config()->set('ticketing.workflow.workflows.broken', [
        'initial' => 'open',
        'states' => ['open', 'closed'],
        'transitions' => ['close' => ['from' => ['open'], 'to' => 'closed', 'guard' => 'No\\Such\\Guard']],
    ]);

    app(ConfigValidator::class)->validate();
})->throws(InvalidConfigurationException::class);

it('rejects a terminal state that is not declared', function (): void {
    config()->set('ticketing.workflow.workflows.broken', [
        'initial' => 'open',
        'states' => ['open'],
        'transitions' => [],
        'terminal' => ['ghost'],
    ]);

    app(ConfigValidator::class)->validate();
})->throws(InvalidConfigurationException::class);

it('rejects a semantic mapping to an undeclared state', function (): void {
    config()->set('ticketing.workflow.workflows.broken', [
        'initial' => 'open',
        'states' => ['open'],
        'transitions' => [],
        'semantics' => ['open' => ['ghost']],
    ]);

    app(ConfigValidator::class)->validate();
})->throws(InvalidConfigurationException::class);
