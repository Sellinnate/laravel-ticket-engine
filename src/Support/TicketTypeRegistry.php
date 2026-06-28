<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Exceptions\UnknownTicketTypeException;
use Selli\Ticketing\Models\TicketType;

/**
 * Resolves a {@see TicketType} by key for the current tenant, lazily
 * provisioning it from the configured defaults the first time it is used. This
 * keeps "time to first ticket" short without forcing a seeder step.
 */
class TicketTypeRegistry
{
    public function resolve(string $key): TicketType
    {
        $model = Ticketing::ticketTypeModel();

        $existing = $model::query()->where('key', $key)->first();

        if ($existing instanceof TicketType) {
            return $existing;
        }

        return $this->provisionFromConfig($key);
    }

    protected function provisionFromConfig(string $key): TicketType
    {
        /** @var array<string, array{name?: string, workflow?: string, default_priority?: int}> $defaults */
        $defaults = config('ticketing.types', []);

        if (! array_key_exists($key, $defaults)) {
            throw UnknownTicketTypeException::forKey($key);
        }

        $definition = $defaults[$key];
        $model = Ticketing::ticketTypeModel();

        /** @var TicketType $type */
        $type = $model::query()->create([
            'key' => $key,
            'name' => $definition['name'] ?? ucfirst($key),
            'workflow' => $definition['workflow'] ?? 'default',
            'default_priority' => Priority::tryFrom($definition['default_priority'] ?? Priority::Normal->value) ?? Priority::Normal,
            'is_active' => true,
        ]);

        return $type;
    }
}
