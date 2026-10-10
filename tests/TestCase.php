<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\TicketingServiceProvider;

use function Orchestra\Testbench\load_migration_paths;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        Ticketing::flushModelBindings();

        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName): string => 'Selli\\Ticketing\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

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
        // A real Laravel app always has an application key; set one so signed
        // CSAT tokens have a secret (the package fails closed without it).
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // Mount the REST API for the suite; the host's auth is its concern, so the
        // tests authenticate via actingAs() and run without auth middleware (the
        // provider always adds SubstituteBindings for route-model binding).
        config()->set('ticketing.api.enabled', true);
        config()->set('ticketing.api.middleware', []);

        // The AI layer is wired for the whole suite (so its listeners run next to
        // every other subsystem) but off until a test picks a mode; turns run
        // inline on the deterministic fake driver.
        config()->set('ticketing.ai.enabled', true);
        config()->set('ticketing.ai.driver', 'fake');
        config()->set('ticketing.ai.queue', false);
        config()->set('ticketing.ai.mode', 'off');

        // The public widget API, mounted without the host's "api" group.
        config()->set('ticketing.widget.enabled', true);
        config()->set('ticketing.widget.middleware', []);

        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', self::databaseConnection());
    }

    /**
     * SQLite in memory by default. Set TICKETING_TEST_DB=mysql|pgsql (plus the
     * TICKETING_TEST_DB_* variables) to run the whole suite against a real
     * server, as the CI database jobs do: the schema is migrated once and every
     * test runs inside a rolled-back transaction.
     *
     * @return array<string, mixed>
     */
    public static function databaseConnection(): array
    {
        $driver = (string) (getenv('TICKETING_TEST_DB') ?: 'sqlite');

        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ];
        }

        return array_filter([
            'driver' => $driver,
            'host' => getenv('TICKETING_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TICKETING_TEST_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('TICKETING_TEST_DB_DATABASE') ?: 'ticketing_test',
            'username' => getenv('TICKETING_TEST_DB_USERNAME') ?: ($driver === 'pgsql' ? 'postgres' : 'root'),
            'password' => getenv('TICKETING_TEST_DB_PASSWORD') ?: '',
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'schema' => $driver === 'pgsql' ? 'public' : null,
            'sslmode' => $driver === 'pgsql' ? 'prefer' : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Register the paths with the migrator RefreshDatabase drives, so the
        // schema is built once per run on a server database (and per test on
        // in-memory SQLite) instead of migrated up and down around every test.
        load_migration_paths($this->app, [
            __DIR__.'/database/migrations',
            __DIR__.'/../database/migrations',
        ]);
    }
}
