<?php

declare(strict_types=1);

use Selli\Ticketing\Tests\BroadcastingTestCase;
use Selli\Ticketing\Tests\Fixtures\TestOrder;
use Selli\Ticketing\Tests\Fixtures\TestUser;
use Selli\Ticketing\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Boots with broadcasting enabled so the provider wires the realtime channels.
uses(BroadcastingTestCase::class)->in('Broadcasting');

/**
 * Create a test user (acts as both requester and agent).
 *
 * @param  array<string, mixed>  $attributes
 */
function makeUser(array $attributes = []): TestUser
{
    return TestUser::query()->create(array_merge([
        'name' => 'Test User',
        'email' => 'user@example.test',
    ], $attributes));
}

/**
 * Create a ticketable order fixture.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeOrder(array $attributes = []): TestOrder
{
    return TestOrder::query()->create(array_merge([
        'number' => 'ORD-'.fake()->unique()->numberBetween(1, 999999),
    ], $attributes));
}
