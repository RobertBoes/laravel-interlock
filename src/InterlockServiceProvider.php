<?php

namespace RobertBoes\Interlock;

use Illuminate\Contracts\Foundation\Application;
use RobertBoes\Interlock\Commands\CacheCommand;
use RobertBoes\Interlock\Commands\ClearCommand;
use RobertBoes\Interlock\Discovery\ModelRegistry;
use RobertBoes\Interlock\Graph\GraphRepository;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class InterlockServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-interlock')
            ->hasConfigFile('interlock')
            ->hasCommands(CacheCommand::class, ClearCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(GraphRepository::class);
        $this->app->singleton(ModelRegistry::class, fn(Application $app) => new ModelRegistry(
            $app->make('config'),
            $app->bootstrapPath('cache/interlock.php'),
        ));
    }

    public function packageBooted(): void
    {
        $this->optimizes(optimize: 'interlock:cache', clear: 'interlock:clear', key: 'interlock');
    }
}
