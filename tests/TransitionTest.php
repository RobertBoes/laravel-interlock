<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Exceptions\IllegalTransitionException;
use RobertBoes\Interlock\Exceptions\InterlockException;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

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
        Post::class . ' has no transition named [pubilsh]. Its transitions are [submit, schedule, publish, retract].',
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

    expect(rescue(fn() => $post->transition($transition), fn(Throwable $e) => $e, report: false))
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

it('leaves the state unchanged and dispatches nothing when a saving listener cancels the save', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    Post::saving(fn() => false);
    Event::fake([Transitioned::class]);

    $post->transition('submit');

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->fresh()->status)->toBe(PostStatus::Draft);
    Event::assertNotDispatched(Transitioned::class);
});
