<?php

use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\BypassedGuardedAttributeWrite;
use RobertBoes\Interlock\Events\BypassedWrite;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineGuardingAnAttributeForAnUnknownTransition;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineGuardingAnAttributeForAnUnlistedTransitionClass;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\OrderGuardingAnAttributeForAnUnknownTransition;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\OrderGuardingAnAttributeForAnUnlistedTransitionClass;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Ship;

it('saves a guarded attribute set in the apply step of a transition allowed to write it', function () {
    $order = orderReadyToShip();

    $order->transition(new Ship('TRACK-1'));

    expect($order->fresh())
        ->status->toBe(OrderStatus::Shipped)
        ->tracking_code->toBe('TRACK-1');
});

it('throws when an order whose tracking code was assigned directly is saved, and writes nothing', function () {
    $order = Order::create()->transition('prepare');
    $order->tracking_code = 'TRACK-1';
    $order->delivered_at = now();

    expect(fn() => $order->save())->toThrow(
        DirectWriteException::class,
        'Cannot save ' . Order::class . ' [' . $order->getKey() . ']: its guarded attribute [tracking_code] was changed outside a transition. Only [ship] may write it.',
    );

    expect($order->fresh())
        ->tracking_code->toBeNull()
        ->delivered_at->toBeNull();
});

it('throws whichever save method writes the tracking code directly, and writes nothing', function (Closure $save) {
    $order = Order::create();

    expect(fn() => $save($order))->toThrow(DirectWriteException::class);

    expect($order->fresh())
        ->tracking_code->toBeNull()
        ->delivered_at->toBeNull();
})->with([
    'save()' => fn(Order $order) => $order->fill(['tracking_code' => 'TRACK-1', 'delivered_at' => now()])->save(),
    'saveQuietly()' => fn(Order $order) => $order->fill(['tracking_code' => 'TRACK-1', 'delivered_at' => now()])->saveQuietly(),
    'update()' => fn(Order $order) => $order->update(['tracking_code' => 'TRACK-1', 'delivered_at' => now()]),
    'updateQuietly()' => fn(Order $order) => $order->updateQuietly(['tracking_code' => 'TRACK-1', 'delivered_at' => now()]),
    'save() inside Model::withoutEvents()' => fn(Order $order) => Order::withoutEvents(
        fn() => $order->fill(['tracking_code' => 'TRACK-1', 'delivered_at' => now()])->save(),
    ),
]);

it('throws when an order is created with a tracking code, and writes nothing', function (Closure $create) {
    expect($create)->toThrow(
        DirectWriteException::class,
        'Cannot create ' . Order::class . ' with guarded attribute [tracking_code] set: no transition runs on create. Only [ship] may write it.',
    );

    expect(Order::count())->toBe(0);
})->with([
    'create()' => fn() => Order::create(['tracking_code' => 'TRACK-1']),
    'createQuietly()' => fn() => Order::createQuietly(['tracking_code' => 'TRACK-1']),
]);

it('saves other attributes of an order with a tracking code freely', function () {
    $order = orderReadyToShip()->transition(new Ship('TRACK-1'));

    $order->update(['delivered_at' => now()]);

    expect($order->fresh())
        ->delivered_at->not->toBeNull()
        ->tracking_code->toBe('TRACK-1');
});

it('throws when a transition that may not write the tracking code saves it, and writes nothing', function (string $transition) {
    $order = Order::create();
    $order->tracking_code = 'TRACK-1';

    expect(fn() => $order->transition($transition))->toThrow(
        DirectWriteException::class,
        'Cannot save ' . Order::class . ' [' . $order->getKey() . "]: its guarded attribute [tracking_code] was changed during transition [{$transition}], which may not write it. Only [ship] may write it.",
    );

    expect($order)
        ->status->toBe(OrderStatus::Pending)
        ->tracking_code->toBe('TRACK-1')
        ->and($order->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->payment_status->toBe(PaymentStatus::Unpaid)
        ->tracking_code->toBeNull();
})->with([
    'of the same machine' => 'prepare',
    'of another machine' => 'authorize_payment',
]);

it('saves a direct write to the tracking code inside a bypass', function () {
    $order = Order::create();

    Interlock::bypass('Backfill tracking codes', fn() => $order->update(['tracking_code' => 'TRACK-1']));

    expect($order->fresh()->tracking_code)->toBe('TRACK-1');
});

it('creates an order with a tracking code inside a bypass', function () {
    $order = Interlock::bypass('Import shipped orders', fn() => Order::create(['tracking_code' => 'TRACK-1']));

    expect($order->fresh()->tracking_code)->toBe('TRACK-1');
});

it('dispatches an event and records history for each bypassed write to the tracking code', function () {
    Event::fake([BypassedGuardedAttributeWrite::class, BypassedWrite::class]);
    $order = Interlock::bypass('Import shipped orders', fn() => Order::create(['tracking_code' => 'TRACK-1']));

    Interlock::bypass('Backfill tracking codes', fn() => $order->update(['tracking_code' => 'TRACK-2', 'delivered_at' => now()]));

    Event::assertDispatchedTimes(BypassedGuardedAttributeWrite::class, 2);
    Event::assertDispatched(fn(BypassedGuardedAttributeWrite $event) => $event->reason === 'Import shipped orders'
        && $event->model === $order
        && $event->attribute === 'tracking_code'
        && $event->oldValue === null
        && $event->newValue === 'TRACK-1');
    Event::assertDispatched(fn(BypassedGuardedAttributeWrite $event) => $event->reason === 'Backfill tracking codes'
        && $event->oldValue === 'TRACK-1'
        && $event->newValue === 'TRACK-2');
    Event::assertNotDispatched(BypassedWrite::class);

    expect($order->history('status')->oldest('id')->get()->map->only([
        'state_attribute', 'transition_name', 'from_state', 'target_state', 'old_attributes', 'new_attributes', 'bypass_reason',
    ])->all())->toBe([
        [
            'state_attribute' => 'status',
            'transition_name' => null,
            'from_state' => null,
            'target_state' => 'pending',
            'old_attributes' => ['tracking_code' => null],
            'new_attributes' => ['tracking_code' => 'TRACK-1'],
            'bypass_reason' => 'Import shipped orders',
        ],
        [
            'state_attribute' => 'status',
            'transition_name' => null,
            'from_state' => 'pending',
            'target_state' => 'pending',
            'old_attributes' => ['tracking_code' => 'TRACK-1'],
            'new_attributes' => ['tracking_code' => 'TRACK-2'],
            'bypass_reason' => 'Backfill tracking codes',
        ],
    ]);
});

it('records a bypassed write to a state attribute and a guarded attribute of one machine as one history entry', function () {
    $order = Order::create();

    Interlock::bypass('Mark a lost parcel as shipped', fn() => $order->update(['status' => OrderStatus::Shipped, 'tracking_code' => 'LOST']));

    expect($order->history('status')->sole())
        ->from_state->toBe('pending')
        ->target_state->toBe('shipped')
        ->old_attributes->toBe(['status' => 'pending', 'tracking_code' => null])
        ->new_attributes->toBe(['status' => 'shipped', 'tracking_code' => 'LOST']);
});

it('saves a direct write to the tracking code in a test that opts into the bypass', function () {
    Interlock::bypassForTest();
    $order = Order::create();

    $order->update(['tracking_code' => 'TRACK-1']);

    expect($order->fresh()->tracking_code)->toBe('TRACK-1');
});

it('rejects a guarded attribute that names an unknown transition when the graph is built', function (Closure $create, string $message) {
    expect($create)->toThrow(InvalidDefinitionException::class, $message);
})->with([
    'by name' => [
        fn() => OrderGuardingAnAttributeForAnUnknownTransition::create(),
        MachineGuardingAnAttributeForAnUnknownTransition::class . ' lets [shpi] write guarded attribute [tracking_code], but has no such transition. Its transitions are [ship].',
    ],
    'by a class the machine does not list' => [
        fn() => OrderGuardingAnAttributeForAnUnlistedTransitionClass::create(),
        MachineGuardingAnAttributeForAnUnlistedTransitionClass::class . ' lets [' . Ship::class . '] write guarded attribute [tracking_code], but has no such transition. Its transitions are [ship].',
    ],
]);
