<?php

declare(strict_types=1);

use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketType;
use Selli\Ticketing\Tenancy\TenantContext;

it('assigns the current tenant on write', function (): void {
    app(TenantContext::class)->forTenant(7, function (): void {
        Ticketing::open(type: 'support', title: 'Tenant 7 ticket', requester: makeUser(['tenant_id' => 7]));
    });

    $ticket = Ticket::query()->withoutTenancy()->first();

    expect($ticket->tenant_id)->toBe(7);
});

it('scopes reads to the current tenant', function (): void {
    $context = app(TenantContext::class);

    $context->forTenant(1, fn () => Ticketing::open(type: 'support', title: 'A', requester: makeUser(['tenant_id' => 1])));
    $context->forTenant(2, fn () => Ticketing::open(type: 'support', title: 'B', requester: makeUser(['tenant_id' => 2])));

    $tenant1Count = $context->forTenant(1, fn (): int => Ticket::query()->count());
    $tenant2Count = $context->forTenant(2, fn (): int => Ticket::query()->count());

    expect($tenant1Count)->toBe(1)
        ->and($tenant2Count)->toBe(1)
        ->and(Ticket::query()->withoutTenancy()->count())->toBe(2);
});

it('never leaks another tenant when no tenant is resolved', function (): void {
    app(TenantContext::class)->forTenant(1, fn () => Ticketing::open(type: 'support', title: 'Secret', requester: makeUser(['tenant_id' => 1])));

    // No tenant context: only shared (null-tenant) rows are visible, never tenant 1's.
    expect(Ticket::query()->count())->toBe(0);
});

it('preserves an explicit null tenant (shared) even while a tenant is active', function (): void {
    app(TenantContext::class)->forTenant(8, function (): void {
        TicketType::query()->create([
            'key' => 'shared-type',
            'name' => 'Shared Type',
            'workflow' => 'default',
            'default_priority' => Priority::Normal,
            'is_active' => true,
            'tenant_id' => null, // explicit shared
        ]);
    });

    $shared = TicketType::query()->withoutTenancy()->where('key', 'shared-type')->first();

    expect($shared->tenant_id)->toBeNull();
});

it('shares null-tenant rows across tenants when enabled', function (): void {
    // Create a null-tenant (shared) ticket directly.
    Ticket::query()->create([
        'reference' => 'SHARED-1',
        'ticket_type_id' => TicketType::factory()->create()->getKey(),
        'title' => 'Shared',
        'status' => 'open',
        'priority' => Priority::Normal,
        'tenant_id' => null,
    ]);

    $visible = app(TenantContext::class)->forTenant(99, fn (): int => Ticket::query()->count());

    expect($visible)->toBe(1);
});
