<?php

namespace RobertBoes\Interlock;

use Illuminate\Contracts\Foundation\Application;
use Pest\Expectation;
use RobertBoes\Interlock\Bypass\BypassScope;
use RobertBoes\Interlock\Commands\CacheCommand;
use RobertBoes\Interlock\Commands\ClearCommand;
use RobertBoes\Interlock\Commands\SignalboxCommand;
use RobertBoes\Interlock\Discovery\ModelRegistry;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\History\DatabaseHistoryRecorder;
use RobertBoes\Interlock\History\HistoryRecorder;
use RobertBoes\Interlock\Testing\PestExpectations;
use RobertBoes\Interlock\Testing\TransitionLog;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class InterlockServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-interlock')
            ->hasConfigFile('interlock')
            ->hasMigration('create_interlock_history_table')
            ->hasCommands(CacheCommand::class, ClearCommand::class, SignalboxCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(GraphRepository::class);
        $this->app->singleton(BypassScope::class);
        $this->app->singleton(HistoryRecorder::class, DatabaseHistoryRecorder::class);
        $this->app->singleton(TransitionLog::class, fn(Application $app) => new TransitionLog(recording: $app->runningUnitTests()));
        $this->app->singleton(ModelRegistry::class, fn(Application $app) => new ModelRegistry(
            $app->make('config'),
            $app->bootstrapPath('cache/interlock.php'),
        ));
    }

    public function packageBooted(): void
    {
        $this->optimizes(optimize: 'interlock:cache', clear: 'interlock:clear', key: 'interlock');

        if ($this->app->runningUnitTests() && class_exists(Expectation::class)) {
            PestExpectations::register();
        }
    }
}
