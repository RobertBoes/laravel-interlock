<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Exceptions\CancelledTransitionException;
use RobertBoes\Interlock\Exceptions\IllegalTransitionException;
use RobertBoes\Interlock\Exceptions\InterlockException;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;

it('stores the target state and returns the model', function () {
    $post = Post::create(['status' => PostStatus::Draft]);

    $result = $post->transition('submit');

    expect($result)->toBe($post)
        ->and($post->fresh()->status)->toBe(PostStatus::Ready);
});

it('writes the model\'s other unsaved changes in the same update', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    $post->title = 'Interlocking for beginners';

    DB::enableQueryLog();
    $post->transition('submit');
    $updates = collect(DB::getQueryLog())->filter(fn(array $query) => str_starts_with(strtolower($query['query']), 'update'));

    expect($updates)->toHaveCount(1)
        ->and($post->fresh())
        ->status->toBe(PostStatus::Ready)
        ->title->toBe('Interlocking for beginners');
});

it('throws when the current state is not one of the transition\'s from-states, and writes nothing', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    $post->title = 'Not yet';

    expect(fn() => $post->transition('publish'))->toThrow(
        IllegalTransitionException::class,
        'Cannot apply transition [publish] to ' . Post::class . ' [' . $post->getKey() . ']: its [status] is [draft], and [publish] only starts from [ready, scheduled].',
    );

    expect($post->fresh())
        ->status->toBe(PostStatus::Draft)
        ->title->toBeNull();
});

it('throws when the model has no transition with the given name', function () {
    $post = Post::create(['status' => PostStatus::Draft]);

    expect(fn() => $post->transition('pubilsh'))->toThrow(
        UnknownTransitionException::class,
        Post::class . ' has no transition named [pubilsh]. Its transitions are [submit, schedule, publish, retract, reschedule].',
    );
});

it('dispatches Transitioned with the model, the transition name, the from-state and the target state', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    Event::fake([Transitioned::class]);

    $post->transition('submit');

    Event::assertDispatchedTimes(Transitioned::class, 1);
    Event::assertDispatched(fn(Transitioned $event) => $event->model === $post
        && $event->transition === 'submit'
        && $event->fromState === PostStatus::Draft
        && $event->targetState === PostStatus::Ready);
});

it('does not dispatch Transitioned when the transition is illegal', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    Event::fake([Transitioned::class]);

    rescue(fn() => $post->transition('publish'), report: false);

    Event::assertNotDispatched(Transitioned::class);
});

it('fires Eloquent\'s save events and touches updated_at', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    $fired = [];
    foreach (['saving', 'updating', 'updated', 'saved'] as $event) {
        Post::{$event}(function () use (&$fired, $event) {
            $fired[] = $event;
        });
    }

    $this->travel(5)->minutes();
    $post->transition('submit');

    expect($fired)->toBe(['saving', 'updating', 'updated', 'saved'])
        ->and($post->fresh()->updated_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('throws exceptions that share the Interlock base exception', function (string $transition) {
    $post = Post::create(['status' => PostStatus::Draft]);

    expect(thrownBy(fn() => $post->transition($transition)))
        ->toBeInstanceOf(InterlockException::class);
})->with([
    'illegal transition' => 'publish',
    'unknown transition' => 'pubilsh',
]);

it('checks the from-states against the stored state, not an unsaved assignment', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    $post->status = PostStatus::Ready;

    expect(fn() => $post->transition('publish'))->toThrow(IllegalTransitionException::class);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

it('throws, leaves the state unchanged and dispatches nothing when a saving listener cancels the save', function (string $event) {
    $post = Post::create(['status' => PostStatus::Draft]);
    Post::{$event}(fn() => false);
    Event::fake([Transitioned::class]);

    expect(fn() => $post->transition('submit'))->toThrow(
        CancelledTransitionException::class,
        'Cannot apply transition [submit] to ' . Post::class . ' [' . $post->getKey() . ']: a saving or updating listener cancelled the save, so nothing was written and its [status] is still [draft].',
    );

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->fresh()->status)->toBe(PostStatus::Draft);
    Event::assertNotDispatched(Transitioned::class);
})->with(['saving', 'updating']);

it('returns false from save() when a saving listener cancels the save of a staged transition', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    Post::saving(fn() => false);

    expect($post->stageTransition('submit')->save())->toBeFalse()
        ->and($post->status)->toBe(PostStatus::Draft);
});

it('lets an updated observer save the model again during the transition', function () {
    $order = Order::create()->transition('prepare');
    Order::updated(function (Order $order) {
        if ($order->delivered_at === null) {
            $order->delivered_at = now();
            $order->saveQuietly();
        }
    });

    $order->transition('create_label');

    expect($order->fresh())
        ->status->toBe(OrderStatus::LabelCreated)
        ->delivered_at->not->toBeNull()
        ->and($order->isDirty())->toBeFalse()
        ->and($order->history('status')->count())->toBe(2);
});

it('lets a saved observer save the model again during the transition', function () {
    $order = Order::create();
    Order::saved(function (Order $order) {
        if ($order->delivered_at === null) {
            $order->delivered_at = now();
            $order->save();
        }
    });

    $order->transition('prepare');

    expect($order->fresh())
        ->status->toBe(OrderStatus::Packing)
        ->delivered_at->not->toBeNull();
});

it('lets the model be saved before the transition writes it, and writes the transition once', function () {
    $order = Order::create();
    Event::listen(Transitioning::class, fn(Transitioning $event) => $event->model->save());
    Event::fake([Transitioned::class]);

    $order->transition('prepare');

    expect($order->fresh()->status)->toBe(OrderStatus::Packing)
        ->and($order->isDirty())->toBeFalse();
    Event::assertDispatchedTimes(Transitioned::class, 1);
});
