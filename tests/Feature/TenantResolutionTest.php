<?php

declare(strict_types=1);

use Selli\Ticketing\Contracts\TenantResolver;
use Selli\Ticketing\Tenancy\TenantContext;

it('resolves the tenant from the authenticated user', function (): void {
    $user = makeUser(['tenant_id' => 42]);

    $this->actingAs($user);

    expect(app(TenantResolver::class)->resolve())->toBe(42)
        ->and(app(TenantContext::class)->current())->toBe(42);
});

it('returns null when no user is authenticated', function (): void {
    expect(app(TenantResolver::class)->resolve())->toBeNull();
});

it('exposes the configured column and shared flag', function (): void {
    $context = app(TenantContext::class);

    expect($context->column())->toBe('tenant_id')
        ->and($context->allowsShared())->toBeTrue()
        ->and($context->enabled())->toBeTrue();
});

it('uses an override ahead of the resolver', function (): void {
    $user = makeUser(['tenant_id' => 1]);
    $this->actingAs($user);

    $value = app(TenantContext::class)->forTenant(99, fn () => app(TenantContext::class)->current());

    expect($value)->toBe(99)
        ->and(app(TenantContext::class)->current())->toBe(1);
});

it('returns null when tenancy is disabled', function (): void {
    config()->set('ticketing.tenancy.enabled', false);
    // Rebuild the context singleton with the new config.
    app()->forgetInstance(TenantContext::class);

    expect(app(TenantContext::class)->current())->toBeNull();
});
