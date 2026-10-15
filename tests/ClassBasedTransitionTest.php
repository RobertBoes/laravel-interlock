<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Exceptions\NotAFromStateException;
use RobertBoes\Interlock\Exceptions\InterlockException;
use RobertBoes\Interlock\Exceptions\MissingTransitionInputException;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\Orders\CancelOrder;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderShipped;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Ship;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Shipment;
use RobertBoes\Interlock\Tests\Fixtures\Returns\ReturnedOrder;
use RobertBoes\Interlock\Tests\Fixtures\Returns\ReturnToSender;

function orderWithLabel(): Order
{
    return Order::create()->transition('prepare')->transition('create_label');
}

/**
 * Records the after-commit events in the order they are dispatched.
 *
 * @return ArrayObject<int, string>
 */
function recordAfterCommitEvents(): ArrayObject
{
    $dispatched = new ArrayObject();
    Event::listen(OrderShipped::class, fn() => $dispatched->append(OrderShipped::class));
    Event::listen(Transitioned::class, fn() => $dispatched->append(Transitioned::class));

    return $dispatched;
}

it('applies a transition passed as an instance of its class, with its typed input', function () {
    $order = orderWithLabel();

    $result = $order->transition(new Ship('TRACK-1'));

    expect($result)->toBe($order)
        ->and($order->fresh())
        ->status->toBe(OrderStatus::Shipped)
        ->tracking_code->toBe('TRACK-1');
});

it('lists fluent and class-based transitions side by side, named after the class unless it overrides the name', function () {
    expect(fn() => Order::create()->transition('shpi'))->toThrow(
        UnknownTransitionException::class,
        Order::class . ' has no transition named [shpi]. Its transitions are [prepare, create_label, ship, deliver, cancel, authorize_payment, capture_payment, refund].',
    );
});

it('compiles the machine without instantiating its transition classes', function () {
    $order = Order::create()->transition('prepare');

    expect($order->fresh()->status)->toBe(OrderStatus::Packing);
});

it('checks a class-based transition\'s from-states, read from its attributes', function () {
    $order = Order::create();
    $order->tracking_code = 'TRACK-1';

    expect(thrownBy(fn() => $order->transition(new Ship('TRACK-1'))))
        ->toBeInstanceOf(NotAFromStateException::class)
        ->model->toBe($order)
        ->transition->toBe('ship')
        ->stateAttribute->toBe('status')
        ->currentState->toBe(OrderStatus::Pending)
        ->fromStates->toBe([OrderStatus::LabelCreated]);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->tracking_code->toBeNull()
        ->and(Shipment::count())->toBe(0);
});

it('applies a class-based transition that overrides its name, by name and as an instance', function (Closure $cancel) {
    $order = Order::create();

    $cancel($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
})->with([
    'by name' => fn(Order $order) => $order->transition('cancel'),
    'as an instance' => fn(Order $order) => $order->transition(new CancelOrder()),
]);

it('throws when a class-based transition that takes input is applied by name', function () {
    $order = orderWithLabel();

    expect(fn() => $order->transition('ship'))->toThrow(
        MissingTransitionInputException::class,
        'Transition [ship] takes input, so ' . Ship::class . ' cannot be built from its name. Apply it as an instance: ->transition(new ' . Ship::class . '(...)).',
    );

    expect($order->fresh()->status)->toBe(OrderStatus::LabelCreated);
});

it('throws when a transition whose input the container could build is applied by name', function () {
    $order = ReturnedOrder::create();

    expect(fn() => $order->transition('return_to_sender'))->toThrow(
        MissingTransitionInputException::class,
        'Transition [return_to_sender] takes input, so ' . ReturnToSender::class . ' cannot be built from its name.',
    );

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('applies a transition whose input is a model when it is given as an instance', function () {
    $order = ReturnedOrder::create();

    $order->transition(new ReturnToSender(Shipment::create(['order_id' => $order->getKey(), 'tracking_code' => 'TRACK-1'])));

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('throws when the transition\'s class is not listed in any of the model\'s machines', function () {
    $post = Post::create();

    expect(fn() => $post->transition(new CancelOrder()))->toThrow(
        UnknownTransitionException::class,
        Post::class . ' has no transition of class ' . CancelOrder::class . '. List the class in one of its state machines. Its transitions are [submit, schedule, publish, retract, reschedule].',
    );
});

it('saves the attributes apply sets and the rows it writes, in the same update as the state', function () {
    $order = orderWithLabel();

    DB::enableQueryLog();
    $order->transition(new Ship('TRACK-1'));
    $updates = collect(DB::getQueryLog())->filter(fn(array $query) => str_starts_with(strtolower($query['query']), 'update'));

    expect($updates)->toHaveCount(1)
        ->and($order->shipments()->pluck('tracking_code')->all())->toBe(['TRACK-1']);
});

it('rolls back the rows apply wrote and keeps the model as it was when the state is stale', function () {
    $order = orderWithLabel();
    Order::find($order->getKey())->transition('cancel');
    Event::fake([OrderShipped::class, Transitioned::class]);

    expect(fn() => $order->transition(new Ship('TRACK-1')))->toThrow(StaleStateException::class);

    expect(Shipment::count())->toBe(0)
        ->and($order->fresh())
        ->status->toBe(OrderStatus::Cancelled)
        ->tracking_code->toBeNull()
        ->and($order)
        ->status->toBe(OrderStatus::LabelCreated)
        ->tracking_code->toBeNull()
        ->isDirty()->toBeFalse();
    Event::assertNotDispatched(OrderShipped::class);
    Event::assertNotDispatched(Transitioned::class);
});

it('rolls back the rows apply wrote when the transition fails after apply', function (Closure $fail) {
    $order = orderWithLabel();
    $fail();
    Event::fake([OrderShipped::class, Transitioned::class]);

    rescue(fn() => $order->transition(new Ship('TRACK-1')), report: false);

    expect(Shipment::count())->toBe(0)
        ->and($order->fresh())
        ->status->toBe(OrderStatus::LabelCreated)
        ->tracking_code->toBeNull()
        ->and($order)
        ->status->toBe(OrderStatus::LabelCreated)
        ->tracking_code->toBeNull();
    Event::assertNotDispatched(OrderShipped::class);
    Event::assertNotDispatched(Transitioned::class);
})->with([
    'a Transitioning listener throws' => fn() => Event::listen(Transitioning::class, fn() => throw new RuntimeException('Not today')),
    'an updating listener throws' => fn() => Order::updating(fn() => throw new RuntimeException('Not today')),
    'a saving listener cancels the save' => fn() => Order::saving(fn() => false),
]);

it('runs the after-commit step and then dispatches Transitioned, once the transition has committed', function () {
    $order = orderWithLabel();
    $dispatched = recordAfterCommitEvents();

    DB::transaction(function () use ($order, $dispatched) {
        $order->transition(new Ship('TRACK-1'));

        expect($dispatched->getArrayCopy())->toBe([]);
    });

    expect($dispatched->getArrayCopy())->toBe([OrderShipped::class, Transitioned::class]);
});

it('runs neither the after-commit step nor Transitioned when an enclosing transaction rolls back', function () {
    $order = orderWithLabel();
    $dispatched = recordAfterCommitEvents();

    rescue(fn() => DB::transaction(function () use ($order) {
        $order->transition(new Ship('TRACK-1'));

        throw new RuntimeException('Roll it all back');
    }), report: false);

    expect($dispatched->getArrayCopy())->toBe([])
        ->and(Shipment::count())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::LabelCreated);
});

it('gives the after-commit step the transition\'s typed input', function () {
    $order = orderWithLabel();
    Event::fake([OrderShipped::class]);

    $order->transition(new Ship('TRACK-1'));

    Event::assertDispatched(fn(OrderShipped $event) => $event->order === $order && $event->trackingCode === 'TRACK-1');
});

it('dispatches Transitioning after apply and before the write', function () {
    $order = orderWithLabel();
    $seen = [];
    Event::listen(Transitioning::class, function (Transitioning $event) use (&$seen) {
        $seen[] = [
            'event' => [$event->model, $event->transition, $event->fromState, $event->targetState],
            'tracking code in memory' => $event->model->getAttribute('tracking_code'),
            'stored status' => DB::table('orders')->where('id', $event->model->getKey())->value('status'),
        ];
    });

    $order->transition(new Ship('TRACK-1'));

    expect($seen)->toBe([[
        'event' => [$order, 'ship', OrderStatus::LabelCreated, OrderStatus::Shipped],
        'tracking code in memory' => 'TRACK-1',
        'stored status' => 'label_created',
    ]]);
});

it('dispatches Transitioning for fluent transitions too', function () {
    $order = Order::create();
    Event::fake([Transitioning::class]);

    $order->transition('prepare');

    Event::assertDispatched(fn(Transitioning $event) => $event->model === $order
        && $event->transition === 'prepare'
        && $event->fromState === OrderStatus::Pending
        && $event->targetState === OrderStatus::Packing);
});

it('throws exceptions that share the Interlock base exception', function (Closure $apply) {
    expect(thrownBy(fn() => $apply(orderWithLabel())))
        ->toBeInstanceOf(InterlockException::class);
})->with([
    'missing input' => fn(Order $order) => $order->transition('ship'),
    'unlisted class' => fn(Order $order) => Post::create()->transition(new CancelOrder()),
]);
