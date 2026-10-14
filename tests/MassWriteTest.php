<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\BypassedMassWrite;
use RobertBoes\Interlock\Events\BypassedWrite;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\InterlockBuilder;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatus;

it('gives interlocked models Interlock\'s Eloquent builder', function () {
    expect(Order::query())->toBeInstanceOf(InterlockBuilder::class);
});

it('throws when a mass update writes a state attribute, and writes nothing', function () {
    $order = Order::create();

    expect(fn() => Order::whereKey($order->getKey())->update(['status' => OrderStatus::Shipped, 'delivered_at' => now()]))->toThrow(
        DirectWriteException::class,
        'Cannot update() ' . Order::class . ' through its query builder: it writes the state attribute [status], which may only change in a transition. Apply a transition to each model instead.',
    );

    expect($order->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->delivered_at->toBeNull();
});

it('throws whichever mass write writes a state attribute, and writes nothing', function (string $method, Closure $write) {
    $order = Order::create();

    expect(fn() => $write(Order::query(), $order))->toThrow(
        DirectWriteException::class,
        'Cannot ' . $method . '() ' . Order::class . ' through its query builder: it writes the state attribute [status], which may only change in a transition.',
    );

    expect(Order::count())->toBe(1);
    expect($order->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->delivery_attempts->toBe(0);
})->with([
    'update()' => ['update', fn(Builder $orders) => $orders->update(['status' => OrderStatus::Shipped])],
    'update() with a qualified column' => ['update', fn(Builder $orders) => $orders->update(['orders.status' => OrderStatus::Shipped])],
    'upsert()' => ['upsert', fn(Builder $orders, Order $order) => $orders->upsert([['id' => $order->getKey(), 'status' => OrderStatus::Shipped->value]], ['id'])],
    'upsert() updating only the state attribute' => ['upsert', fn(Builder $orders, Order $order) => $orders->upsert([['id' => $order->getKey(), 'delivery_attempts' => 1]], ['id'], ['status' => OrderStatus::Shipped->value])],
    'increment()' => ['increment', fn(Builder $orders) => $orders->increment('delivery_attempts', 1, ['status' => OrderStatus::Shipped])],
    'decrement()' => ['decrement', fn(Builder $orders) => $orders->decrement('delivery_attempts', 1, ['status' => OrderStatus::Shipped])],
    'incrementEach()' => ['incrementEach', fn(Builder $orders) => $orders->incrementEach(['delivery_attempts' => 1], ['status' => OrderStatus::Shipped])],
    'decrementEach()' => ['decrementEach', fn(Builder $orders) => $orders->decrementEach(['delivery_attempts' => 1], ['status' => OrderStatus::Shipped])],
    'insert()' => ['insert', fn(Builder $orders) => $orders->insert(['status' => OrderStatus::Shipped->value])],
    'insert() of several rows' => ['insert', fn(Builder $orders) => $orders->insert([['status' => OrderStatus::Pending->value, 'delivery_attempts' => 1], ['status' => OrderStatus::Shipped->value]])],
    'insertOrIgnore()' => ['insertOrIgnore', fn(Builder $orders) => $orders->insertOrIgnore(['status' => OrderStatus::Shipped->value])],
    'insertGetId()' => ['insertGetId', fn(Builder $orders) => $orders->insertGetId(['status' => OrderStatus::Shipped->value])],
    'the static Model::insert()' => ['insert', fn() => Order::insert(['status' => OrderStatus::Shipped->value])],
    'increment() on a model' => ['increment', fn(Builder $orders, Order $order) => $order->increment('delivery_attempts', 1, ['status' => OrderStatus::Shipped])],
]);

it('throws whichever mass write writes a guarded attribute, and writes nothing', function (string $method, Closure $write) {
    $order = Order::create();

    expect(fn() => $write(Order::query(), $order))->toThrow(
        DirectWriteException::class,
        'Cannot ' . $method . '() ' . Order::class . ' through its query builder: it writes the guarded attribute [tracking_code], which may only change in a transition. Apply a transition to each model instead.',
    );

    expect(Order::count())->toBe(1);
    expect($order->fresh()->tracking_code)->toBeNull();
})->with([
    'update()' => ['update', fn(Builder $orders) => $orders->update(['tracking_code' => 'TRACK-1'])],
    'upsert()' => ['upsert', fn(Builder $orders, Order $order) => $orders->upsert([['id' => $order->getKey(), 'status' => OrderStatus::Pending->value, 'tracking_code' => 'TRACK-1']], ['id'], ['tracking_code'])],
    'increment()' => ['increment', fn(Builder $orders) => $orders->increment('delivery_attempts', 1, ['tracking_code' => 'TRACK-1'])],
    'insert()' => ['insert', fn(Builder $orders) => $orders->insert(['tracking_code' => 'TRACK-1'])],
]);

it('names every state attribute and guarded attribute a mass write writes', function () {
    expect(fn() => Order::query()->update([
        'status' => OrderStatus::Shipped,
        'payment_status' => PaymentStatus::Paid,
        'tracking_code' => 'TRACK-1',
    ]))->toThrow(
        DirectWriteException::class,
        'it writes the state attributes [status, payment_status] and the guarded attribute [tracking_code], which may only change in a transition.',
    );
});

it('leaves mass writes to other attributes alone', function (Closure $write) {
    $order = Order::create(['delivery_attempts' => 2]);

    $write(Order::query(), $order);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->delivery_attempts->toBe(3);
})->with([
    'update()' => fn(Builder $orders) => $orders->update(['delivery_attempts' => 3]),
    'upsert()' => fn(Builder $orders, Order $order) => $orders->upsert([['id' => $order->getKey(), 'status' => OrderStatus::Pending->value, 'delivery_attempts' => 3]], ['id'], ['delivery_attempts']),
    'increment()' => fn(Builder $orders) => $orders->increment('delivery_attempts', 1, ['delivered_at' => null]),
    'decrement()' => fn(Builder $orders) => $orders->decrement('delivery_attempts', -1),
    'incrementEach()' => fn(Builder $orders) => $orders->incrementEach(['delivery_attempts' => 1]),
    'decrementEach()' => fn(Builder $orders) => $orders->decrementEach(['delivery_attempts' => -1]),
    'increment() on a model' => fn(Builder $orders, Order $order) => $order->increment('delivery_attempts'),
]);

it('leaves mass inserts in an initial state alone, as creating a model is', function (Closure $insert) {
    $insert(Order::query());

    expect(Order::sole())
        ->status->toBe(OrderStatus::Pending)
        ->tracking_code->toBeNull()
        ->delivery_attempts->toBe(1);
})->with([
    'insert()' => fn(Builder $orders) => $orders->insert(['status' => OrderStatus::Pending->value, 'delivery_attempts' => 1]),
    'insert() of an enum case' => fn(Builder $orders) => $orders->insert(['status' => OrderStatus::Pending, 'delivery_attempts' => 1]),
    'insert() leaving a guarded attribute null' => fn(Builder $orders) => $orders->insert(['status' => OrderStatus::Pending->value, 'tracking_code' => null, 'delivery_attempts' => 1]),
    'insertOrIgnore()' => fn(Builder $orders) => $orders->insertOrIgnore(['status' => OrderStatus::Pending->value, 'delivery_attempts' => 1]),
    'insertGetId()' => fn(Builder $orders) => $orders->insertGetId(['status' => OrderStatus::Pending->value, 'delivery_attempts' => 1]),
    'upsert() of a new row' => fn(Builder $orders) => $orders->upsert([['id' => 1, 'status' => OrderStatus::Pending->value, 'delivery_attempts' => 1]], ['id'], ['delivery_attempts']),
]);

it('throws when a mass upsert updates a state attribute, even to an initial state', function () {
    $order = Order::create(['delivery_attempts' => 2]);

    expect(fn() => Order::query()->upsert([['id' => $order->getKey(), 'status' => OrderStatus::Pending->value]], ['id']))
        ->toThrow(DirectWriteException::class, 'it writes the state attribute [status]');
});

it('makes mass writes to state attributes and guarded attributes inside a bypass, and dispatches an event for each', function () {
    Event::fake([BypassedMassWrite::class, BypassedWrite::class]);
    $order = Order::create();

    Interlock::bypass('Mark the lost orders as cancelled', function () use ($order) {
        Order::whereKey($order->getKey())->update(['status' => OrderStatus::Cancelled, 'tracking_code' => 'LOST']);
        Order::insert(['status' => OrderStatus::Delivered->value]);
    });

    expect($order->fresh())
        ->status->toBe(OrderStatus::Cancelled)
        ->tracking_code->toBe('LOST');
    expect(Order::where('status', OrderStatus::Delivered)->count())->toBe(1);

    Event::assertDispatchedTimes(BypassedMassWrite::class, 2);
    Event::assertDispatched(fn(BypassedMassWrite $event) => $event->reason === 'Mark the lost orders as cancelled'
        && $event->model === Order::class
        && $event->method === 'update'
        && $event->attributes === ['status', 'tracking_code']);
    Event::assertDispatched(fn(BypassedMassWrite $event) => $event->method === 'insert' && $event->attributes === ['status']);
    Event::assertNotDispatched(BypassedWrite::class);
});

it('dispatches no event for mass writes inside a bypass that Interlock allows anyway', function () {
    Event::fake([BypassedMassWrite::class]);

    Interlock::bypass('Retry the deliveries', function () {
        Order::insert(['status' => OrderStatus::Pending->value]);
        Order::query()->increment('delivery_attempts');
    });

    expect(Order::sole()->delivery_attempts)->toBe(1);
    Event::assertNotDispatched(BypassedMassWrite::class);
});

it('dispatches no event for a bypassed mass write that fails', function () {
    Event::fake([BypassedMassWrite::class]);

    rescue(fn() => Interlock::bypass('Mark the lost orders as cancelled', fn() => Order::query()->update([
        'status' => OrderStatus::Cancelled,
        'column_that_does_not_exist' => 'fails the update',
    ])), report: false);

    Event::assertNotDispatched(BypassedMassWrite::class);
});

it('dispatches one event for a bypassed write through a model save, not a mass write event too', function () {
    Event::fake([BypassedMassWrite::class, BypassedWrite::class]);
    $order = Order::create();

    Interlock::bypass('Fix a stuck order', fn() => $order->update(['status' => OrderStatus::Delivered]));
    Interlock::bypass('Import a delivered order', fn() => Order::create(['status' => OrderStatus::Delivered]));

    Event::assertDispatchedTimes(BypassedWrite::class, 2);
    Event::assertNotDispatched(BypassedMassWrite::class);
});

it('rejects mass writes again once the bypass ends', function () {
    Interlock::bypass('Mark the lost orders as cancelled', fn() => null);

    expect(fn() => Order::query()->update(['status' => OrderStatus::Cancelled]))->toThrow(DirectWriteException::class);
});
