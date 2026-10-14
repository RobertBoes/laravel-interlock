<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderDelivered;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Ship;

function shippedOrder(): Order
{
    return Order::create()->transition('prepare')->transition('create_label')->transition(new Ship('TRACK-1'));
}

it('saves the attributes a fluent apply closure sets in the same update as the state', function () {
    $order = shippedOrder();
    $this->freezeSecond();

    DB::enableQueryLog();
    $order->transition('deliver');
    $updates = collect(DB::getQueryLog())->filter(fn(array $query) => str_starts_with(strtolower($query['query']), 'update'));

    expect($updates)->toHaveCount(1)
        ->and($order->fresh())
        ->status->toBe(OrderStatus::Delivered)
        ->delivered_at->toDateTimeString()->toBe(now()->toDateTimeString());
});

it('runs a fluent after-commit closure and then dispatches Transitioned, once the transition has committed', function () {
    $order = shippedOrder();
    $dispatched = [];
    Event::listen(OrderDelivered::class, function (OrderDelivered $event) use (&$dispatched, $order) {
        $dispatched[] = $event->order === $order ? OrderDelivered::class : 'another order';
    });
    Event::listen(Transitioned::class, function () use (&$dispatched) {
        $dispatched[] = Transitioned::class;
    });

    DB::transaction(function () use ($order, &$dispatched) {
        $order->transition('deliver');

        expect($dispatched)->toBe([]);
    });

    expect($dispatched)->toBe([OrderDelivered::class, Transitioned::class]);
});

it('runs no fluent after-commit closure and keeps the model as it was when the state is stale', function () {
    $order = shippedOrder();
    Order::find($order->getKey())->transition('deliver');
    Event::fake([OrderDelivered::class]);

    expect(fn() => $order->transition('deliver'))->toThrow(StaleStateException::class);

    expect($order)
        ->status->toBe(OrderStatus::Shipped)
        ->delivered_at->toBeNull();
    Event::assertNotDispatched(OrderDelivered::class);
});
