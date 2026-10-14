<?php

namespace RobertBoes\Interlock;

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
}
