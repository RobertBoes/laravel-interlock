<?php

return [

    'discovery' => [

        // The directories scanned, recursively, for models with the #[UseStateMachine] attribute.
        'paths' => [
            app_path('Models'),
        ],

    ],

    // Interlocked models outside the scanned paths, such as models from packages or modules.
    // A service provider can add to these with Interlock::registerModels().
    'models' => [],

    'triggers' => [

        // How many matching models a trigger loads at a time, in key order, to apply its transition to.
        'chunk_size' => 100,

        // The most matching models one run of a trigger applies its transition to. A bigger backlog drains over the
        // next runs, each carrying on where the last one stopped.
        'max_per_run' => 1000,

        // Interlock adds `interlock:triggers` to the scheduler. Turn that off to schedule the command yourself, or
        // change how often it runs. No trigger fires while the command doesn't run.
        'schedule' => [
            'enabled' => true,
            'cron' => '* * * * *',
        ],

    ],

];
