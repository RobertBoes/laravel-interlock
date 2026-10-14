<?php

use RobertBoes\Interlock\InterlockServiceProvider;

it('boots the service provider', function () {
    expect(app()->getProviders(InterlockServiceProvider::class))->toHaveCount(1);
});

it('merges the package config', function () {
    expect(config('interlock'))->toBeArray();
});

it('publishes the config file under the interlock-config tag', function () {
    $published = InterlockServiceProvider::pathsToPublish(InterlockServiceProvider::class, 'interlock-config');

    expect(array_values($published))->toBe([config_path('interlock.php')]);
});
