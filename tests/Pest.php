<?php

declare(strict_types=1);

use Selli\Ticketing\Tests\BroadcastingTestCase;
use Selli\Ticketing\Tests\Fixtures\TestOrder;
use Selli\Ticketing\Tests\Fixtures\TestUser;
use Selli\Ticketing\Tests\MigrationTestCase;
use Selli\Ticketing\Tests\RagTestCase;
use Selli\Ticketing\Tests\TestCase;
use Selli\Ticketing\Tests\WidgetApiGroupTestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Base path of the REST API, shared by every API test file.
const API = '/ticketing/api/v1';

// The widget API behind the host's real "api" middleware group.
uses(WidgetApiGroupTestCase::class)->in('Widget');

// Hand-run migrations: no RefreshDatabase transaction around the DDL.
uses(MigrationTestCase::class)->in('Migrations');

// Boots with broadcasting enabled so the provider wires the realtime channels.
uses(BroadcastingTestCase::class)->in('Broadcasting');

// Boots with the RAG Engine bridge on (in-memory vectors, fake LLM).
uses(RagTestCase::class)->in('Rag');

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
