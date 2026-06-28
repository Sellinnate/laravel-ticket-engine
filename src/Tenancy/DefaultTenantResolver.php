<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tenancy;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Selli\Ticketing\Contracts\TenantResolver;

/**
 * Default tenant resolution from the authenticated user.
 *
 * It reads the configured tenant column off the current user (e.g. a user's
 * `tenant_id`). Applications with a different notion of "current tenant" bind
 * their own resolver, or a bridge to stancl/spatie tenancy.
 */
class DefaultTenantResolver implements TenantResolver
{
    public function __construct(
        protected AuthFactory $auth,
        protected string $column = 'tenant_id',
    ) {}

    public function resolve(): int|string|null
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        if (method_exists($user, 'getAttribute')) {
            /** @var int|string|null $value */
            $value = $user->getAttribute($this->column);

            return $value;
        }

        return $user->{$this->column} ?? null;
    }
}
