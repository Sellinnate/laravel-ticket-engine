<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;

/**
 * Shared helpers for the package migrations so table names, primary keys, the
 * tenant column and polymorphic columns stay consistent and configurable.
 *
 * Polymorphic IDs are stored as nullable strings on purpose: the package is
 * agnostic about the host key type (int, ULID, UUID), so a subject/author/actor
 * may point at any model regardless of its own key strategy.
 */
trait HasTicketingSchema
{
    protected function table(string $key): string
    {
        $prefix = (string) config('ticketing.tables.prefix', '');
        $name = (string) config('ticketing.tables.'.$key, $key);

        return $prefix.$name;
    }

    protected function usesUlids(): bool
    {
        return config('ticketing.ids.type') === 'ulid';
    }

    protected function primaryKey(Blueprint $table, string $name = 'id'): void
    {
        if ($this->usesUlids()) {
            $table->ulid($name)->primary();

            return;
        }

        $table->id($name);
    }

    /**
     * A nullable reference to another package row (FK-style) honouring the ID
     * strategy.
     */
    protected function foreignId(Blueprint $table, string $column): void
    {
        if ($this->usesUlids()) {
            $table->ulid($column)->nullable();

            return;
        }

        $table->unsignedBigInteger($column)->nullable();
    }

    protected function tenantColumn(Blueprint $table): void
    {
        // The column is always created (nullable). When tenancy is disabled it
        // simply stays null — runtime code still reads/writes it, and the
        // unique/index helpers leave it out of scoped keys (see uniqueScoped).
        $column = (string) config('ticketing.tenancy.column', 'tenant_id');
        $type = (string) config('ticketing.tenancy.column_type', 'unsignedBigInteger');

        match ($type) {
            'uuid' => $table->uuid($column)->nullable(),
            'ulid' => $table->ulid($column)->nullable(),
            'string' => $table->string($column)->nullable(),
            default => $table->unsignedBigInteger($column)->nullable(),
        };

        $table->index($column);
    }

    /**
     * Nullable polymorphic columns with a string id (agnostic to host keys).
     */
    protected function nullableMorph(Blueprint $table, string $name): void
    {
        $table->string("{$name}_type")->nullable();
        $table->string("{$name}_id")->nullable();
        $table->index(["{$name}_type", "{$name}_id"]);
    }

    protected function requiredMorph(Blueprint $table, string $name): void
    {
        $table->string("{$name}_type");
        $table->string("{$name}_id");
        $table->index(["{$name}_type", "{$name}_id"]);
    }

    protected function tenantName(): string
    {
        return (string) config('ticketing.tenancy.column', 'tenant_id');
    }

    protected function tenancyEnabled(): bool
    {
        return config('ticketing.tenancy.enabled', true) !== false;
    }

    /**
     * A unique index scoped to the tenant when tenancy is enabled.
     *
     * @param  list<string>  $columns
     */
    protected function uniqueScoped(Blueprint $table, array $columns, ?string $name = null): void
    {
        if ($this->tenancyEnabled()) {
            array_unshift($columns, $this->tenantName());
        }

        $table->unique($columns, $name);
    }

    /**
     * An index scoped to the tenant when tenancy is enabled.
     *
     * @param  list<string>  $columns
     */
    protected function indexScoped(Blueprint $table, array $columns, ?string $name = null): void
    {
        if ($this->tenancyEnabled()) {
            array_unshift($columns, $this->tenantName());
        }

        $table->index($columns, $name);
    }
}
