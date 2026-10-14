<?php

namespace RobertBoes\Interlock\Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use RobertBoes\Interlock\InterlockServiceProvider;

abstract class TestCase extends Orchestra
{
    use LazilyRefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [InterlockServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Testbench defaults to its own in-memory "testing" connection; CI overrides it with mysql or pgsql.
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/database/migrations');
    }
}
