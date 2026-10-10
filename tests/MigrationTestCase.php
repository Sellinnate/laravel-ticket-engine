<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Selli\Ticketing\TicketingServiceProvider;

/**
 * For tests that run migrations by hand: no RefreshDatabase and no automatic
 * schema, so DDL (which MySQL commits implicitly) never runs inside a test
 * transaction. Each test cleans up the tables it creates.
 */
class MigrationTestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            TicketingServiceProvider::class,
        ];
    }

    public function defineEnvironment($app): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', TestCase::databaseConnection());
    }
}
