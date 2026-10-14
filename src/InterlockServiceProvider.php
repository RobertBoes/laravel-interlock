<?php

namespace RobertBoes\Interlock;

use RobertBoes\Interlock\Graph\GraphRepository;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class InterlockServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-interlock')
            ->hasConfigFile('interlock');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(GraphRepository::class);
    }
}
