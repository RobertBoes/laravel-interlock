<?php

use RobertBoes\Interlock\History\DatabaseHistoryRecorder;

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

    'history' => [

        // The class that writes history for the machines that record it, an implementation of
        // RobertBoes\Interlock\History\HistoryRecorder. It is called inside the write's transaction.
        'recorder' => DatabaseHistoryRecorder::class,

    ],

    'triggers' => [

        // How many matching models a trigger loads at a time, in key order, to apply its transition to. A trigger that
        // fans out puts each chunk's keys in its job's payload: with UUID or ULID keys, a chunk of around 5,000
        // exceeds the 256 KB message limit of SQS.
        'chunk_size' => 100,

        // The most matching models one run of a trigger applies its transition to. A bigger backlog drains over the
        // next runs, each carrying on where the last one stopped.
        'max_per_run' => 1000,

        // The seconds a trigger job may run. Keep it below the `retry_after` of the queue connection (90 by default),
        // or the queue starts a slow run again beside the first. A job stops taking on models once three quarters of it
        // has passed, and the next run carries on from there. 0 means no timeout, as it does on the queue, but a run
        // must still finish within `retry_after`: size `max_per_run` and `chunk_size` for it.
        'timeout' => 60,

        // The minutes after which `interlock:triggers:check` reports a trigger whose condition matches models as
        // overdue: when no run of it has finished within them, or models have matched it at the start of every run
        // for longer.
        'overdue_after' => 15,

        // The queue connection and queue the trigger jobs are pushed to. Null uses the app's default.
        'connection' => null,
        'queue' => null,

        // Interlock adds `interlock:triggers` to the scheduler. Turn that off to schedule the command yourself, or
        // change how often it runs. No trigger fires while the command doesn't run.
        'schedule' => [
            'enabled' => true,
            'cron' => '* * * * *',
        ],

    ],

];
