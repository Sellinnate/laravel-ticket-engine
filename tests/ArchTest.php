<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Events\TicketBroadcasted;
use Selli\Ticketing\Exceptions\TicketingException;
use Selli\Ticketing\TicketingServiceProvider;

arch('no debugging helpers leak into the package')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

arch('the whole package uses strict types')
    ->expect('Selli\Ticketing')
    ->toUseStrictTypes();

arch('enums live in the Enums namespace')
    ->expect('Selli\Ticketing\Enums')
    ->toBeEnums();

arch('contracts are interfaces')
    ->expect('Selli\Ticketing\Contracts')
    ->toBeInterfaces();

arch('every Eloquent model is tenant-scoped')
    ->expect('Selli\Ticketing\Models')
    ->classes()
    ->toUseTrait(BelongsToTenant::class)
    ->ignoring([
        'Selli\Ticketing\Models\Concerns',
        'Selli\Ticketing\Models\Builders',
    ]);

arch('actions expose a handle method')
    ->expect('Selli\Ticketing\Actions')
    ->toHaveMethod('handle');

arch('the domain never depends on the HTTP layer')
    ->expect([
        'Selli\Ticketing\Actions',
        'Selli\Ticketing\Automation',
        'Selli\Ticketing\Contracts',
        'Selli\Ticketing\Data',
        'Selli\Ticketing\Events',
        'Selli\Ticketing\Gdpr',
        'Selli\Ticketing\Models',
        'Selli\Ticketing\Query',
        'Selli\Ticketing\Routing',
        'Selli\Ticketing\Sla',
        'Selli\Ticketing\Workflow',
    ])
    ->not->toUse('Selli\Ticketing\Http');

arch('public contracts, events and data objects never reach into internals')
    ->expect([
        'Selli\Ticketing\Contracts',
        'Selli\Ticketing\Data',
        'Selli\Ticketing\Events',
    ])
    ->not->toUse([
        'Selli\Ticketing\Automation',
        'Selli\Ticketing\Commands',
        'Selli\Ticketing\Gdpr',
        'Selli\Ticketing\Http',
        'Selli\Ticketing\Jobs',
        'Selli\Ticketing\Listeners',
        'Selli\Ticketing\Sla',
    ]);

arch('listeners are only wired by the service provider')
    ->expect('Selli\Ticketing\Listeners')
    ->toOnlyBeUsedIn([
        TicketingServiceProvider::class,
        'Selli\Ticketing\Listeners',
    ]);

arch('HTTP controllers are only reached through the package routes')
    ->expect('Selli\Ticketing\Http\Controllers')
    ->toOnlyBeUsedIn('Selli\Ticketing\Http\Controllers');

arch('data objects are immutable value objects')
    ->expect('Selli\Ticketing\Data')
    ->toBeFinal()
    ->toBeReadonly();

arch('every exception extends the package base exception')
    ->expect('Selli\Ticketing\Exceptions')
    ->toExtend(TicketingException::class)
    ->ignoring(TicketingException::class);

arch('domain events are dispatched only after the transaction commits')
    ->expect('Selli\Ticketing\Events')
    ->toImplement(ShouldDispatchAfterCommit::class)
    ->ignoring(TicketBroadcasted::class);

arch('configuration is read through config(), never env()')
    ->expect('env')
    ->not->toBeUsed();

arch('no unsafe PHP primitives')
    ->preset()
    ->security();
