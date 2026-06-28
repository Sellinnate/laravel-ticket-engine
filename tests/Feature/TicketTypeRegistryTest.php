<?php

declare(strict_types=1);

use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Support\TicketTypeRegistry;
use Selli\Ticketing\Tenancy\TenantContext;

it('prefers a tenant-specific type over a shared one with the same key', function (): void {
    // A shared (null-tenant) type with the same key.
    TicketType::query()->create([
        'key' => 'support',
        'name' => 'Shared Support',
        'workflow' => 'default',
        'default_priority' => Priority::Low,
        'is_active' => true,
        'tenant_id' => null,
    ]);

    $type = app(TenantContext::class)->forTenant(1, function () {
        TicketType::query()->create([
            'key' => 'support',
            'name' => 'Tenant Support',
            'workflow' => 'incident',
            'default_priority' => Priority::High,
            'is_active' => true,
            'tenant_id' => 1,
        ]);

        return app(TicketTypeRegistry::class)->resolve('support');
    });

    expect($type->name)->toBe('Tenant Support');
});

it('falls back to a shared type when the tenant has none', function (): void {
    TicketType::query()->create([
        'key' => 'support',
        'name' => 'Shared Support',
        'workflow' => 'default',
        'default_priority' => Priority::Low,
        'is_active' => true,
        'tenant_id' => null,
    ]);

    $type = app(TenantContext::class)->forTenant(5, fn () => app(TicketTypeRegistry::class)->resolve('support'));

    expect($type->name)->toBe('Shared Support')
        ->and($type->tenant_id)->toBeNull();
});

it('resolves types when tenancy is disabled', function (): void {
    config()->set('ticketing.tenancy.enabled', false);
    app()->forgetInstance(TenantContext::class);

    $type = app(TicketTypeRegistry::class)->resolve('support');

    expect($type->key)->toBe('support');
});
