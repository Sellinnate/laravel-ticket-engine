<?php

declare(strict_types=1);

use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Facades\Ticketing;

it('seeds a working demo ticket', function (): void {
    $this->artisan('ticketing:demo')
        ->expectsOutputToContain('Created demo ticket')
        ->assertSuccessful();

    $ticket = Ticketing::ticketModel()::query()->withoutGlobalScopes()->latest('id')->first();

    expect($ticket)->not->toBeNull()
        ->and($ticket->title)->toBe('Welcome to your ticketing engine')
        ->and($ticket->messages()->count())->toBe(2)
        ->and($ticket->messages()->where('visibility', MessageVisibility::Internal->value)->count())->toBe(1);
});

it('honours the --type option for the demo', function (): void {
    $this->artisan('ticketing:demo', ['--type' => 'incident'])->assertSuccessful();

    $ticket = Ticketing::ticketModel()::query()->withoutGlobalScopes()->latest('id')->first();

    expect($ticket->type->key)->toBe('incident');
});

it('fails gracefully on an unknown demo type', function (): void {
    $this->artisan('ticketing:demo', ['--type' => 'no-such-type'])
        ->assertFailed();
});

it('registers the install command', function (): void {
    expect(array_key_exists('ticketing:install', $this->app[\Illuminate\Contracts\Console\Kernel::class]->all()))->toBeTrue();
});
