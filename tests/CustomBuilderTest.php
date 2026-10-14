<?php

use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\BypassedMassWrite;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\MissingBuilderTraitException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\RejectsDirectWrites;
use RobertBoes\Interlock\Tests\Fixtures\CustomBuilders\BuilderWithoutTrait;
use RobertBoes\Interlock\Tests\Fixtures\CustomBuilders\OrderBuilder;
use RobertBoes\Interlock\Tests\Fixtures\CustomBuilders\OrderWithBuilderAttribute;
use RobertBoes\Interlock\Tests\Fixtures\CustomBuilders\OrderWithBuilderAttributeWithoutTrait;
use RobertBoes\Interlock\Tests\Fixtures\CustomBuilders\OrderWithBuilderMethod;
use RobertBoes\Interlock\Tests\Fixtures\CustomBuilders\OrderWithBuilderMethodWithoutTrait;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;

dataset('models with a custom builder using the trait', [
    'newEloquentBuilder()' => OrderWithBuilderMethod::class,
    'the builder attribute' => OrderWithBuilderAttribute::class,
]);

dataset('models with a custom builder without the trait', [
    'newEloquentBuilder()' => OrderWithBuilderMethodWithoutTrait::class,
    'the builder attribute' => OrderWithBuilderAttributeWithoutTrait::class,
]);

it('keeps the custom builder of a model', function (string $model) {
    $model::create(['delivery_attempts' => 1]);

    expect($model::query())->toBeInstanceOf(OrderBuilder::class)
        ->and($model::query()->retried()->count())->toBe(1);
})->with('models with a custom builder using the trait');

it('throws when a mass write through the custom builder writes a state attribute, and writes nothing', function (string $model) {
    $order = $model::create();

    expect(fn() => $model::query()->update(['status' => OrderStatus::Shipped]))->toThrow(
        DirectWriteException::class,
        'Cannot update() ' . $model . ' through its query builder: it writes the state attribute [status], which may only change in a transition.',
    );

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
})->with('models with a custom builder using the trait');

it('makes mass writes through the custom builder inside a bypass, and dispatches the event', function (string $model) {
    Event::fake([BypassedMassWrite::class]);
    $order = $model::create();

    Interlock::bypass('Mark the lost orders as cancelled', fn() => $model::query()->update(['status' => OrderStatus::Cancelled]));

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    Event::assertDispatched(fn(BypassedMassWrite $event) => $event->model === $model && $event->attributes === ['status']);
})->with('models with a custom builder using the trait');

it('saves and transitions a model with a custom builder as usual', function (string $model) {
    $order = $model::create()->transition('prepare');
    $order->update(['delivery_attempts' => 1]);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Packing)
        ->delivery_attempts->toBe(1);
})->with('models with a custom builder using the trait');

it('raises a configuration error saying how to fix it when a custom builder lacks the trait', function (string $model) {
    expect(fn() => new $model())->toThrow(
        MissingBuilderTraitException::class,
        sprintf(
            '%s is interlocked, but its Eloquent builder %s does not use %s, so mass writes through it would skip Interlock. Add `use \\%s;` to %s.',
            $model,
            BuilderWithoutTrait::class,
            RejectsDirectWrites::class,
            RejectsDirectWrites::class,
            BuilderWithoutTrait::class,
        ),
    );
})->with('models with a custom builder without the trait');

it('raises the configuration error every time such a model is used, not only the first time', function (string $model) {
    rescue(fn() => new $model(), report: false);

    expect(fn() => $model::query())->toThrow(MissingBuilderTraitException::class);
})->with('models with a custom builder without the trait');
