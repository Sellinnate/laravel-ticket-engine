<?php

declare(strict_types=1);

use Selli\Ticketing\Concerns\BelongsToTenant;

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
