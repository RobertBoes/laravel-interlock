<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('migrates the database on the connection CI selected', function () {
    expect(DB::connection()->getName())->toBe(env('DB_CONNECTION'))
        ->and(Schema::hasTable('migrations'))->toBeTrue();
});
