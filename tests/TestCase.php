<?php

namespace RobertBoes\Interlock\Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use RobertBoes\Interlock\InterlockServiceProvider;

use function Orchestra\Testbench\load_migration_paths;

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
        // Not loadMigrationsFrom(): once the database is migrated, it runs and rolls back the migrations in every
        // later test itself, and on MySQL that rollback commits the test's transaction and hangs the next test.
        load_migration_paths($this->app, __DIR__ . '/Fixtures/database/migrations');
    }
}
