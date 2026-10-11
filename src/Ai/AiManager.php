<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

use Closure;
use Illuminate\Contracts\Container\Container;
use Selli\Ticketing\Ai\Drivers\FakeAiDriver;
use Selli\Ticketing\Ai\Drivers\NullAiDriver;
use Selli\Ticketing\Contracts\AiDriver;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Integrations\RagEngine\RagAiDriver;
use Selli\Ticketing\Integrations\RagEngine\RagKnowledge;
use Sellinnate\RagEngine\RagEngine;

/**
 * Resolves the configured {@see AiDriver}. Ships `null` (never answers, so
 * every AI turn escalates) and `fake` (deterministic, for tests and demos);
 * the RAG Engine bridge registers `rag-engine`; hosts add their own:
 *
 * ```php
 * app(AiManager::class)->extend('openai', fn ($app, array $config) => new MyOpenAiDriver($config));
 * ```
 */
class AiManager
{
    /** @var array<string, Closure(Container, array<string, mixed>): AiDriver> */
    protected array $customDrivers = [];

    /** @var array<string, AiDriver> */
    protected array $resolved = [];

    public function __construct(protected Container $container) {}

    /**
     * @param  Closure(Container, array<string, mixed>): AiDriver  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->customDrivers[$name] = $factory;
        unset($this->resolved[$name]);
    }

    public function driver(?string $name = null): AiDriver
    {
        $name ??= $this->defaultDriver();

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    public function defaultDriver(): string
    {
        $driver = config('ticketing.ai.driver', 'null');

        return is_string($driver) && $driver !== '' ? $driver : 'null';
    }

    /**
     * Drop resolved instances (e.g. after changing config at runtime).
     */
    /**
     * @param  array<array-key, mixed>  $config
     */
    protected function ragEngine(array $config): AiDriver
    {
        if (! class_exists(RagEngine::class)) {
            throw new InvalidConfigurationException('The rag-engine AI driver needs sellinnate/rag-engine: composer require sellinnate/rag-engine');
        }

        /** @var array<string, mixed> $config */
        return new RagAiDriver($this->container->make(RagKnowledge::class), $config);
    }

    public function forgetDrivers(): void
    {
        $this->resolved = [];
    }

    protected function resolve(string $name): AiDriver
    {
        /** @var array<string, mixed> $config */
        $config = (array) config("ticketing.ai.drivers.{$name}", []);

        if (isset($this->customDrivers[$name])) {
            return ($this->customDrivers[$name])($this->container, $config);
        }

        return match ($name) {
            'null' => new NullAiDriver,
            'fake' => $this->container->make(FakeAiDriver::class),
            'rag-engine' => $this->ragEngine($config),
            default => throw new InvalidConfigurationException("Unknown AI driver [{$name}]."),
        };
    }
}
