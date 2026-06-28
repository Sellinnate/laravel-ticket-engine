<?php

declare(strict_types=1);

namespace Selli\Ticketing\Routing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Selli\Ticketing\Contracts\AssignmentStrategy;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Routing\Strategies\LeastBusyStrategy;
use Selli\Ticketing\Routing\Strategies\ManualStrategy;
use Selli\Ticketing\Routing\Strategies\RoundRobinStrategy;
use Selli\Ticketing\Routing\Strategies\SkillBasedStrategy;

/**
 * Resolves the active {@see AssignmentStrategy}. Apps register their own with
 * extend() — e.g. AssignmentManager::extend('priority-weighted', …) — without
 * touching the core.
 */
class AssignmentManager
{
    /** @var array<string, Closure(Container): AssignmentStrategy> */
    protected array $customStrategies = [];

    public function __construct(protected Container $container) {}

    /**
     * @param  Closure(Container): AssignmentStrategy  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->customStrategies[$name] = $factory;
    }

    public function strategy(?string $name = null): AssignmentStrategy
    {
        $name ??= (string) config('ticketing.routing.default_strategy', 'manual');

        if (isset($this->customStrategies[$name])) {
            return ($this->customStrategies[$name])($this->container);
        }

        return match ($name) {
            'manual' => $this->container->make(ManualStrategy::class),
            'round-robin' => $this->container->make(RoundRobinStrategy::class),
            'least-busy' => $this->container->make(LeastBusyStrategy::class),
            'skill-based' => $this->container->make(SkillBasedStrategy::class),
            default => throw new InvalidConfigurationException("Unknown assignment strategy [{$name}]."),
        };
    }
}
