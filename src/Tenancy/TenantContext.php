<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tenancy;

use Closure;
use Selli\Ticketing\Contracts\TenantResolver;

/**
 * The single authority for "what tenant are we operating as right now".
 *
 * Reads delegate to the bound {@see TenantResolver} unless an explicit override
 * has been pushed (for CLI, queues, the email channel, and escalation sweeps
 * that iterate per tenant). This keeps tenancy structural rather than relying on
 * implicit, uncontrollable global state.
 */
class TenantContext
{
    /** @var list<int|string|null> */
    protected array $overrides = [];

    public function __construct(
        protected TenantResolver $resolver,
        protected bool $enabled = true,
        protected string $column = 'tenant_id',
        protected bool $allowShared = true,
    ) {}

    /**
     * The current tenant identifier, or null when none is active.
     */
    public function current(): int|string|null
    {
        if (! $this->enabled) {
            return null;
        }

        if ($this->overrides !== []) {
            return $this->overrides[array_key_last($this->overrides)];
        }

        return $this->resolver->resolve();
    }

    /**
     * Run a callback acting as the given tenant, restoring the previous context
     * afterwards even if the callback throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function forTenant(int|string|null $tenantId, Closure $callback): mixed
    {
        $this->overrides[] = $tenantId;

        try {
            return $callback();
        } finally {
            array_pop($this->overrides);
        }
    }

    /**
     * Whether tenant scoping is active.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The name of the tenant foreign-key column used across package tables.
     */
    public function column(): string
    {
        return $this->column;
    }

    /**
     * Whether records with a null tenant are treated as shared (visible to all).
     */
    public function allowsShared(): bool
    {
        return $this->allowShared;
    }
}
