<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RobertBoes\Interlock\History\HistoryEntry;
use RobertBoes\Interlock\Tests\Fixtures\Documents\UlidDocument;
use RobertBoes\Interlock\Tests\Fixtures\Documents\UuidDocument;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;

dataset('key types', [
    'an integer key' => [fn() => Order::create()->transition('prepare')],
    'a ULID key' => [fn() => UlidDocument::create()->transition('publish')],
    'a UUID key' => [fn() => UuidDocument::create()->transition('publish')],
]);

/**
 * Two models of each key type, so every lookup has another model's history to leave out.
 *
 * @return list<Model>
 */
function modelsOfEveryKeyType(): array
{
    return [
        Order::create()->transition('prepare'),
        Order::create()->transition('prepare'),
        UlidDocument::create()->transition('publish'),
        UlidDocument::create()->transition('publish'),
        UuidDocument::create()->transition('publish'),
        UuidDocument::create()->transition('publish'),
    ];
}

it('records the key of every key type as a string, in the one table', function (Model $model) {
    expect(HistoryEntry::sole())
        ->model_type->toBe($model->getMorphClass())
        ->model_id->toBe((string) $model->getKey());
})->with('key types');

it('reads each model\'s own history, whatever its key type', function () {
    $models = modelsOfEveryKeyType();

    foreach ($models as $model) {
        expect($model->history()->pluck('model_id')->all())->toBe([(string) $model->getKey()]);
    }
});

it('eager loads each model\'s own history, whatever its key type', function () {
    $models = modelsOfEveryKeyType();

    foreach ([Order::class, UlidDocument::class, UuidDocument::class] as $class) {
        foreach ($class::with('history')->get() as $loaded) {
            expect($loaded->history->pluck('model_id')->all())->toBe([(string) $loaded->getKey()]);
        }
    }

    expect(HistoryEntry::count())->toBe(count($models));
});

it('queries for history and counts it, whatever the key type', function () {
    modelsOfEveryKeyType();
    $untouched = [Order::create(), UlidDocument::create(), UuidDocument::create()];

    foreach ($untouched as $model) {
        expect($model::query()->whereHas('history')->count())->toBe(2)
            ->and($model::query()->whereDoesntHave('history')->sole()->is($model))->toBeTrue()
            ->and($model::query()->withCount('history')->find($model->getKey())->history_count)->toBe(0);
    }
});

it('loads the model of every key type from its history entry', function () {
    $models = modelsOfEveryKeyType();
    $entries = HistoryEntry::with('model')->orderBy('id')->get();

    foreach ($models as $index => $model) {
        expect($entries[$index]->model->is($model))->toBeTrue()
            ->and(HistoryEntry::find($entries[$index]->id)->model->is($model))->toBeTrue();
    }
});

it('binds an integer key as a string, so the database compares strings and uses its index', function () {
    $order = Order::create()->transition('prepare');
    $bindings = [];
    DB::listen(function (QueryExecuted $query) use (&$bindings) {
        if (str_contains($query->sql, 'interlock_history')) {
            $bindings = [...$bindings, ...$query->bindings];
        }
    });

    $order->history()->get();
    Order::with('history')->whereKey($order->getKey())->get();

    expect($bindings)->toContain((string) $order->getKey())
        ->and(array_filter($bindings, is_int(...)))->toBe([]);
});
