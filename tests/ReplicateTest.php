<?php

use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\ImportedPost;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Ship;

function shippedAndAuthorizedOrder(): Order
{
    return Order::create()
        ->transition('prepare')
        ->transition('create_label')
        ->transition(new Ship('TRACK-1'))
        ->transition('authorize_payment');
}

it('starts a copy of an order over in its initial states, without its tracking code', function () {
    $copy = shippedAndAuthorizedOrder()->replicate();

    expect($copy)
        ->status->toBe(OrderStatus::Pending)
        ->payment_status->toBe(PaymentStatus::Unpaid)
        ->tracking_code->toBeNull();
});

it('saves a copy of an order that has left its initial states', function () {
    $order = shippedAndAuthorizedOrder();

    $copy = $order->replicate();
    $copy->save();

    expect($copy->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->payment_status->toBe(PaymentStatus::Unpaid)
        ->tracking_code->toBeNull()
        ->and($order->fresh())
        ->status->toBe(OrderStatus::Shipped)
        ->payment_status->toBe(PaymentStatus::Authorized)
        ->tracking_code->toBe('TRACK-1');
});

it('leaves the state of a copy null when its machine has several initial states', function () {
    $post = ImportedPost::create(['status' => PostStatus::Ready])->transition('publish');

    $copy = $post->replicate();

    expect($copy->status)->toBeNull();

    $copy->status = PostStatus::Draft;
    $copy->save();

    expect($copy->fresh()->status)->toBe(PostStatus::Draft);
});

it('keeps the copy\'s other attributes', function () {
    $order = Order::create()->transition('prepare');
    $order->update(['delivered_at' => '2026-01-01 09:00:00']);

    $copy = $order->replicate();

    expect($copy->delivered_at->toDateTimeString())->toBe('2026-01-01 09:00:00');
});

it('lets a replicating listener choose the copy\'s initial state', function () {
    ImportedPost::replicating(function (ImportedPost $copy) {
        $copy->status = PostStatus::Ready;
    });
    $post = ImportedPost::create(['status' => PostStatus::Draft])->transition('submit')->transition('publish');

    $copy = $post->replicate();
    $copy->save();

    expect($copy->fresh()->status)->toBe(PostStatus::Ready);
});
