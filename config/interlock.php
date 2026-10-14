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

    ],

];
