<?php

use Illuminate\Support\Facades\DB;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\IllegalTransitionException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithParentInitialState;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithParentTargetState;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\OrderWithParentInitialState;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\OrderWithParentTargetState;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\OtherStatus;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\StatusWithCyclicParents;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\StatusWithParentOfAnotherEnum;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;

function orderIn(OrderStatus $status): Order
{
    $order = Order::create();

    return match ($status) {
        OrderStatus::Pending => $order,
        OrderStatus::Packing => $order->transition('prepare'),
        OrderStatus::LabelCreated => $order->transition('prepare')->transition('create_label'),
        OrderStatus::Shipped => $order->transition('prepare')->transition('create_label')->transition('ship'),
        default => throw new LogicException("No fixture path to [{$status->value}]."),
    };
}

it('applies a transition from a parent state from each of its children', function (OrderStatus $child) {
    $order = orderIn($child);

    $order->transition('cancel');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
})->with([OrderStatus::Packing, OrderStatus::LabelCreated]);

it('stores the leaf state in the one state attribute', function () {
    $order = orderIn(OrderStatus::Packing);

    $order->transition('create_label');

    expect(DB::table('orders')->where('id', $order->getKey())->value('status'))->toBe('label_created');
});

it('still rejects a transition from a parent state when the model is not within it', function () {
    $order = orderIn(OrderStatus::Shipped);

    expect(fn() => $order->transition('cancel'))->toThrow(
        IllegalTransitionException::class,
        'Cannot apply transition [cancel] to ' . Order::class . ' [' . $order->getKey() . ']: its [status] is [shipped], and [cancel] only starts from [pending, preparing].',
    );

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

it('rejects a transition whose target state is a parent state', function () {
    expect(fn() => (new OrderWithParentTargetState())->transition('prepare'))->toThrow(
        InvalidDefinitionException::class,
        'Transition [prepare] in ' . MachineWithParentTargetState::class . ' targets parent state [preparing]. A model is always in a leaf state, so target one of [packing, label_created] instead.',
    );
});

it('throws when an order is created in a parent state, and writes nothing', function (Closure $create) {
    expect($create)->toThrow(
        DirectWriteException::class,
        'Cannot create ' . Order::class . ' with [status] [preparing]: it is a parent state, and a model is always in a leaf state. Its leaf states are [packing, label_created].',
    );

    expect(Order::count())->toBe(0);
})->with([
    'create()' => fn() => Order::create(['status' => OrderStatus::Preparing]),
    'createQuietly()' => fn() => Order::createQuietly(['status' => OrderStatus::Preparing]),
]);

it('throws when an order whose status was set to a parent state is saved, and writes nothing', function (Closure $save) {
    $order = orderIn(OrderStatus::Packing);

    expect(fn() => $save($order))->toThrow(
        DirectWriteException::class,
        'Cannot save ' . Order::class . ' [' . $order->getKey() . ']: its [status] was set to parent state [preparing], and a model is always in a leaf state. Its leaf states are [packing, label_created].',
    );

    expect($order->fresh()->status)->toBe(OrderStatus::Packing);
})->with([
    'save()' => fn(Order $order) => $order->fill(['status' => OrderStatus::Preparing])->save(),
    'saveQuietly()' => fn(Order $order) => $order->fill(['status' => OrderStatus::Preparing])->saveQuietly(),
]);

it('throws when a parent state is stored inside a bypass, and writes nothing', function () {
    $order = orderIn(OrderStatus::Packing);

    expect(fn() => Interlock::bypass('Fix stuck orders', fn() => $order->update(['status' => OrderStatus::Preparing])))
        ->toThrow(DirectWriteException::class)
        ->and(fn() => Interlock::bypass('Import orders', fn() => Order::create(['status' => OrderStatus::Preparing])))
        ->toThrow(DirectWriteException::class);

    expect(Order::count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Packing);
});

it('answers whether a state is, or is within, a given state', function (BackedEnum $state, BackedEnum $other, bool $isWithin) {
    expect(Interlock::isWithin($state, $other))->toBe($isWithin);
})->with([
    'a child within its parent' => [OrderStatus::Packing, OrderStatus::Preparing, true],
    'another child within the same parent' => [OrderStatus::LabelCreated, OrderStatus::Preparing, true],
    'a leaf state and itself' => [OrderStatus::Packing, OrderStatus::Packing, true],
    'a parent state and itself' => [OrderStatus::Preparing, OrderStatus::Preparing, true],
    'a parent within one of its children' => [OrderStatus::Preparing, OrderStatus::Packing, false],
    'a child within its sibling' => [OrderStatus::Packing, OrderStatus::LabelCreated, false],
    'a state outside the parent' => [OrderStatus::Shipped, OrderStatus::Preparing, false],
    'states of different enums' => [PostStatus::Draft, OrderStatus::Pending, false],
]);

it('rejects a parent state of another enum', function () {
    expect(fn() => Interlock::isWithin(StatusWithParentOfAnotherEnum::Reviewing, StatusWithParentOfAnotherEnum::Open))->toThrow(
        InvalidDefinitionException::class,
        StatusWithParentOfAnotherEnum::class . '::Reviewing declares parent state [open] of ' . OtherStatus::class . '. A parent state must be a case of the same enum.',
    );
});

it('rejects parent states that nest a state within itself', function () {
    expect(fn() => Interlock::isWithin(StatusWithCyclicParents::Reviewing, StatusWithCyclicParents::Open))->toThrow(
        InvalidDefinitionException::class,
        StatusWithCyclicParents::class . '::Reviewing is nested within itself: [reviewing, approving, reviewing]. Remove one of these #[ParentState] attributes.',
    );
});

it('rejects a parent state as an initial state, and writes nothing', function () {
    expect(fn() => OrderWithParentInitialState::create(['status' => OrderStatus::Pending]))->toThrow(
        InvalidDefinitionException::class,
        MachineWithParentInitialState::class . ' declares parent state [preparing] as an initial state. A model is always in a leaf state, so declare one of [packing, label_created] instead.',
    );

    expect(OrderWithParentInitialState::count())->toBe(0);
});
