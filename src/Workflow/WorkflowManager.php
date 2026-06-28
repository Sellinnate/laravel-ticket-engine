<?php

declare(strict_types=1);

namespace Selli\Ticketing\Workflow;

use Closure;
use Illuminate\Contracts\Container\Container;
use Selli\Ticketing\Contracts\WorkflowDriver;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;

/**
 * Resolves the active {@see WorkflowDriver}. Custom drivers (e.g. a bridge to
 * spatie/laravel-model-states) register via extend() without touching the core.
 */
class WorkflowManager
{
    /** @var array<string, Closure(Container): WorkflowDriver> */
    protected array $customDrivers = [];

    /** @var array<string, WorkflowDriver> */
    protected array $resolved = [];

    public function __construct(protected Container $container) {}

    /**
     * Register a custom driver factory.
     *
     * @param  Closure(Container): WorkflowDriver  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->customDrivers[$name] = $factory;
        unset($this->resolved[$name]);
    }

    public function driver(?string $name = null): WorkflowDriver
    {
        $name ??= (string) config('ticketing.workflow.driver', 'config');

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    protected function resolve(string $name): WorkflowDriver
    {
        if (isset($this->customDrivers[$name])) {
            return ($this->customDrivers[$name])($this->container);
        }

        return match ($name) {
            'config' => $this->container->make(ConfigWorkflowDriver::class),
            default => throw new InvalidConfigurationException("Unknown workflow driver [{$name}]."),
        };
    }
}
