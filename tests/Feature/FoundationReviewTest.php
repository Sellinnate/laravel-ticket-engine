<?php

declare(strict_types=1);

use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Workflow\ConfigValidator;

// --- Tenant auto-assignment honours an explicit value (incl. child inheritance) -

it('honours an explicit tenant on write (auto-assigns only when omitted)', function (): void {
    $context = app(TenantContext::class);

    $context->forTenant(1, function (): void {
        // Omitted → auto-assigned to the current tenant.
        $auto = Team::query()->create(['name' => 'Auto']);
        // Explicit null → a deliberate shared row, kept.
        $shared = Team::query()->create(['name' => 'Shared', 'tenant_id' => null]);
        // Explicit value (e.g. a child inheriting its parent's tenant, which may
        // differ from the ambient context) → honoured, never rejected.
        $explicit = Team::query()->create(['name' => 'Inherited', 'tenant_id' => 2]);

        expect((string) $auto->tenant_id)->toBe('1')
            ->and($shared->tenant_id)->toBeNull()
            ->and((string) $explicit->tenant_id)->toBe('2');
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

it('rejects scalar config containers and a non-string type workflow', function (): void {
    $validator = app(ConfigValidator::class);

    config()->set('ticketing.workflow.workflows', 'not-an-array');
    expect(fn () => $validator->validate())->toThrow(InvalidConfigurationException::class);

    config()->set('ticketing.workflow.workflows', ['default' => ['initial' => 'open', 'states' => ['open'], 'transitions' => []]]);
    config()->set('ticketing.types', 'not-an-array');
    expect(fn () => $validator->validate())->toThrow(InvalidConfigurationException::class);

    config()->set('ticketing.types', ['support' => ['workflow' => ['array-not-string']]]);
    expect(fn () => $validator->validate())->toThrow(InvalidConfigurationException::class);
});
