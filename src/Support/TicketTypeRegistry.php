<?php

declare(strict_types=1);

namespace Selli\Ticketing\Support;

use Illuminate\Database\Eloquent\Builder;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Exceptions\UnknownTicketTypeException;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Resolves a {@see TicketType} by key for the current tenant, lazily
 * provisioning it from the configured defaults the first time it is used. This
 * keeps "time to first ticket" short without forcing a seeder step.
 *
 * When both a tenant-specific and a shared (null-tenant) type share the same
 * key, the tenant-specific row wins deterministically.
 */
class TicketTypeRegistry
{
    public function __construct(protected TenantContext $tenant) {}

    public function resolve(string $key): TicketType
    {
        $existing = $this->findPreferringTenant($key);

        if ($existing instanceof TicketType) {
            return $existing;
        }

        return $this->provisionFromConfig($key);
    }

    /**
     * Find a type by key, preferring the current tenant's own row over a shared
     * (null-tenant) one rather than relying on undefined ordering.
     */
    protected function findPreferringTenant(string $key): ?TicketType
    {
        $model = Ticketing::ticketTypeModel();
        $column = $this->tenant->column();
        $tenant = $this->tenant->current();

        if ($this->tenant->enabled() && $tenant !== null) {
            $own = $model::query()
                ->withoutTenancy()
                ->where('key', $key)
                ->where($column, $tenant)
                ->first();

            if ($own instanceof TicketType) {
                return $own;
            }

            if ($this->tenant->allowsShared()) {
                return $model::query()
                    ->withoutTenancy()
                    ->where('key', $key)
                    ->whereNull($column)
                    ->first();
            }

            return null;
        }

        // Tenancy disabled, or no tenant resolved: match within the default scope.
        return $model::query()
            ->where('key', $key)
            ->when(
                ! $this->tenant->enabled(),
                fn (Builder $query): Builder => $query,
                fn (Builder $query): Builder => $query->whereNull($column),
            )
            ->first();
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

        // createOrFirst is race-safe against the (tenant_id, key) unique index:
        // a concurrent first-use of the same type returns the existing row
        // instead of throwing a duplicate-key error.
        /** @var TicketType $type */
        $type = $model::query()->createOrFirst(['key' => $key], [
            'name' => $definition['name'] ?? ucfirst($key),
            'workflow' => $definition['workflow'] ?? 'default',
            'default_priority' => Priority::tryFrom($definition['default_priority'] ?? Priority::Normal->value) ?? Priority::Normal,
            'is_active' => true,
        ]);

        return $type;
    }
}
