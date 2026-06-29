<?php

declare(strict_types=1);

use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Workflow\ConfigValidator;

// --- #4: cross-tenant WRITE guard (the global scope only guards reads) ---------

it('refuses an explicit write to a different tenant than the resolved one', function (): void {
    $context = app(TenantContext::class);

    // In tenant 1, creating a package row with an explicit tenant_id of 2 is a
    // cross-tenant write and must be refused.
    $context->forTenant(1, function (): void {
        expect(fn () => Team::query()->create(['name' => 'Smuggled', 'tenant_id' => 2]))
            ->toThrow(CrossTenantException::class);
    });
});

it('allows an explicit shared (null) write and a matching-tenant write', function (): void {
    $context = app(TenantContext::class);

    $context->forTenant(1, function (): void {
        $shared = Team::query()->create(['name' => 'Shared', 'tenant_id' => null]);
        $matching = Team::query()->create(['name' => 'Mine', 'tenant_id' => 1]);

        expect($shared->tenant_id)->toBeNull()
            ->and((string) $matching->tenant_id)->toBe('1');
    });
});

// --- #3: first_response_at is stamped once, atomically -------------------------

it('does not move first_response_at on a later public reply', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::for($ticket)->postMessage(makeUser(), 'first agent reply');
    $first = $ticket->fresh()->first_response_at;
    expect($first)->not->toBeNull();

    Ticketing::for($ticket)->postMessage(makeUser(), 'second agent reply');

    // The conditional whereNull update means the first stamp wins and is never
    // overwritten by a later reply.
    expect($ticket->fresh()->first_response_at->equalTo($first))->toBeTrue();
});

// --- #9 / #11: config validator fails CLOSED on a malformed shape --------------

it('rejects a malformed workflow/type/guard config with a clear exception', function (): void {
    $validator = app(ConfigValidator::class);

    config()->set('ticketing.workflow.workflows', ['broken' => 'not-an-array']);
    expect(fn () => $validator->validate())->toThrow(InvalidConfigurationException::class);

    config()->set('ticketing.workflow.workflows', [
        'default' => ['initial' => 'open', 'states' => ['open'], 'transitions' => []],
    ]);
    config()->set('ticketing.types', ['support' => 'not-an-array']);
    expect(fn () => $validator->validate())->toThrow(InvalidConfigurationException::class);

    config()->set('ticketing.types', []);
    config()->set('ticketing.workflow.workflows', [
        'default' => [
            'initial' => 'open',
            'states' => ['open', 'closed'],
            'transitions' => ['close' => ['from' => ['open'], 'to' => 'closed', 'guard' => [['not' => 'a-string']]]],
        ],
    ]);
    expect(fn () => $validator->validate())->toThrow(InvalidConfigurationException::class);
});
