<?php

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Exceptions\NotAFromStateException;
use RobertBoes\Interlock\Exceptions\InvalidFakeException;
use RobertBoes\Interlock\Exceptions\MissingTransitionInputException;
use RobertBoes\Interlock\Exceptions\TriggerOnlyTransitionException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Expense;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\ExpenseStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderDelivered;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderShipped;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Ship;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Shipment;
use RobertBoes\Interlock\Tests\Fixtures\TriggerOnlyPosts\TriggerOnlyPost;

it('records a class-based transition and writes its target state without running its apply and after-commit steps', function () {
    $order = orderReadyToShip();
    Event::fake([OrderShipped::class]);
    Interlock::fake();

    $order->transition(new Ship('TRACK-1'));

    Interlock::assertTransitioned($order, 'ship');
    expect($order->fresh())
        ->status->toBe(OrderStatus::Shipped)
        ->tracking_code->toBeNull()
        ->and(Shipment::count())->toBe(0);
    Event::assertNotDispatched(OrderShipped::class);
});

it('records a fluent transition without running its apply and after-commit closures', function () {
    $order = orderReadyToShip()->transition(new Ship('TRACK-1'));
    Event::fake([OrderDelivered::class]);
    Interlock::fake();

    $order->transition('deliver');

    Interlock::assertTransitioned($order, 'deliver');
    expect($order->fresh())
        ->status->toBe(OrderStatus::Delivered)
        ->delivered_at->toBeNull();
    Event::assertNotDispatched(OrderDelivered::class);
});

it('still dispatches Transitioned while faked', function () {
    $order = Order::create();
    Event::fake([Transitioned::class]);
    Interlock::fake();

    $order->transition('prepare');

    Event::assertDispatched(fn(Transitioned $event) => $event->model === $order && $event->transition === 'prepare');
});

it('still rejects a transition the calling code may not apply while faked, and does not record it', function (string $transition, Closure $apply, string $exception) {
    $order = orderReadyToShip();
    Interlock::fake();

    expect(fn() => $apply($order))->toThrow($exception);

    Interlock::assertNotTransitioned($order, $transition);
    expect($order->fresh()->status)->toBe(OrderStatus::LabelCreated);
})->with([
    'not from a from-state' => ['deliver', fn(Order $order) => $order->transition('deliver'), NotAFromStateException::class],
    'applied by name without its input' => ['ship', fn(Order $order) => $order->transition('ship'), MissingTransitionInputException::class],
]);

it('skips authorization while faked', function () {
    $expense = Expense::create(['amount' => 4200])->transition('submit');
    Interlock::fake();

    $expense->transition('reject', Employee::staff());

    Interlock::assertTransitioned($expense, 'reject');
    expect($expense->fresh()->status)->toBe(ExpenseStatus::Rejected);
});

it('still refuses a trigger-only transition applied by hand while faked, and does not record it', function (Closure $apply) {
    $post = TriggerOnlyPost::create()->transition('submit');
    Interlock::fake();

    expect(fn() => $apply($post))->toThrow(TriggerOnlyTransitionException::class, 'Only its trigger applies transition [publish].');

    Interlock::assertNotTransitioned($post, 'publish');
    expect($post->fresh()->status)->toBe(PostStatus::Ready);
})->with([
    'applied' => [fn(TriggerOnlyPost $post) => $post->transition('publish', Employee::manager())],
    'staged' => [fn(TriggerOnlyPost $post) => $post->stageTransition('publish', Employee::manager())->save()],
]);

it('fails the assertions while faked as it does for real transitions', function () {
    $order = orderReadyToShip();
    Interlock::fake();

    $order->transition(new Ship('TRACK-1'));

    expect(fn() => Interlock::assertNotTransitioned($order, 'ship'))->toThrow(AssertionFailedError::class)
        ->and(fn() => Interlock::assertTransitioned($order, 'cancel'))->toThrow(AssertionFailedError::class);
});

it('ends the fake with the test, so the next test runs the steps again', function () {
    Interlock::fake();

    // A fresh app, as the next test gets, with the fixture tables it would get too.
    $this->refreshApplication();
    $this->defineDatabaseMigrations();
    $this->refreshDatabase();
    Event::fake([OrderShipped::class]);
    $order = orderReadyToShip()->transition(new Ship('TRACK-1'));

    expect($order->fresh()->tracking_code)->toBe('TRACK-1')
        ->and(Shipment::count())->toBe(1);
    Event::assertDispatched(OrderShipped::class);
});

it('starts the next test without the transitions recorded in this one', function () {
    $order = Order::create()->transition('prepare');

    $this->refreshApplication();

    Interlock::assertNotTransitioned($order);
});

it('refuses to fake outside the test environment', function () {
    $this->app['env'] = 'production';

    expect(fn() => Interlock::fake())->toThrow(
        InvalidFakeException::class,
        'Interlock::fake() only works while running tests, because it skips the apply and after-commit steps of every transition.',
    );
});
