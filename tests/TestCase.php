<?php

declare(strict_types=1);

namespace Selli\Ticketing\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\TicketingServiceProvider;

class TestCase extends Orchestra
{
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

        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->setUpFixtureTables();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Create the host-application tables the fixtures use (users, orders).
     */
    protected function setUpFixtureTables(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->nullable();
                $table->boolean('available')->default(true);
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table): void {
                $table->id();
                $table->string('number');
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->timestamps();
            });
        }
    }
}
