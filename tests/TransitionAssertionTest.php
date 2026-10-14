<?php

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;

it('passes assertTransitioned once the model was transitioned by the transition', function () {
    $order = Order::create()->transition('prepare');

    Interlock::assertTransitioned($order, 'prepare');
});

it('fails assertTransitioned when the model was not transitioned by the transition, naming those it was', function () {
    $order = Order::create()->transition('prepare');

    expect(fn() => Interlock::assertTransitioned($order, 'cancel'))->toThrow(
        AssertionFailedError::class,
        'The expected transition [cancel] was not applied to ' . Order::class . ' [' . $order->getKey() . ']. The transitions applied to it were [prepare].',
    );
});

it('counts a transition applied through another instance of the same row', function () {
    $order = Order::create();

    Order::find($order->getKey())->transition('prepare');

    Interlock::assertTransitioned($order, 'prepare');
});

it('does not count transitions applied to another row', function () {
    $order = Order::create();
    Order::create()->transition('prepare');

    expect(fn() => Interlock::assertTransitioned($order, 'prepare'))->toThrow(
        AssertionFailedError::class,
        'The expected transition [prepare] was not applied to ' . Order::class . ' [' . $order->getKey() . ']. No transitions were applied to it.',
    );
    Interlock::assertNotTransitioned($order);
});

it('does not count a transition that did not commit', function (string $transition, Closure $fail) {
    $order = Order::create();

    rescue(fn() => $fail($order), report: false);

    Interlock::assertNotTransitioned($order, $transition);
})->with([
    'an illegal transition' => ['create_label', fn(Order $order) => $order->transition('create_label')],
    'stale state' => ['prepare', function (Order $order) {
        Order::find($order->getKey())->transition('cancel');
        $order->transition('prepare');
    }],
    'an enclosing transaction rolls back' => ['prepare', fn(Order $order) => DB::transaction(function () use ($order) {
        $order->transition('prepare');

        throw new RuntimeException('Roll it all back');
    })],
]);

it('passes assertNotTransitioned for a transition the model was not transitioned by', function () {
    $order = Order::create()->transition('prepare');

    Interlock::assertNotTransitioned($order, 'cancel');
});

it('fails assertNotTransitioned when the model was transitioned by the transition', function () {
    $order = Order::create()->transition('prepare');

    expect(fn() => Interlock::assertNotTransitioned($order, 'prepare'))->toThrow(
        AssertionFailedError::class,
        'The unexpected transition [prepare] was applied to ' . Order::class . ' [' . $order->getKey() . '].',
    );
});

it('fails assertNotTransitioned without a transition when the model was transitioned at all, naming the transitions', function () {
    $order = Order::create()->transition('prepare')->transition('create_label');

    expect(fn() => Interlock::assertNotTransitioned($order))->toThrow(
        AssertionFailedError::class,
        'Unexpected transitions were applied to ' . Order::class . ' [' . $order->getKey() . ']: [prepare, create_label].',
    );
});

it('throws for a transition the model does not have, so a misspelt name cannot pass', function (Closure $assert) {
    $order = Order::create();

    expect(fn() => $assert($order))->toThrow(
        UnknownTransitionException::class,
        Order::class . ' has no transition named [shpi].',
    );
})->with([
    'assertTransitioned' => fn(Order $order) => Interlock::assertTransitioned($order, 'shpi'),
    'assertNotTransitioned' => fn(Order $order) => Interlock::assertNotTransitioned($order, 'shpi'),
]);
