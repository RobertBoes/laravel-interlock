<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Exceptions\InterlockException;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

it('throws stale state when another instance transitioned the same row first', function () {
    $post = Post::create()->transition('submit');
    $first = Post::find($post->getKey());
    $second = Post::find($post->getKey());

    $first->transition('publish');

    expect(fn() => $second->transition('retract'))->toThrow(
        StaleStateException::class,
        'Cannot apply transition [retract] to ' . Post::class . ' [' . $post->getKey() . ']: its [status] was [ready] when it was loaded, but the stored [status] is now [published]. Reload the model and try again.',
    );

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});

it('writes none of the model\'s other unsaved changes when the state is stale', function () {
    $post = Post::create(['title' => 'Original title'])->transition('submit');
    $first = Post::find($post->getKey());
    $second = Post::find($post->getKey());
    $first->transition('publish');

    $second->title = 'Edited title';
    rescue(fn() => $second->transition('retract'), report: false);

    expect($post->fresh())
        ->status->toBe(PostStatus::Published)
        ->title->toBe('Original title');
});

it('keeps the model\'s unsaved changes in memory when the state is stale', function () {
    $post = Post::create(['title' => 'Original title'])->transition('submit');
    $first = Post::find($post->getKey());
    $second = Post::find($post->getKey());
    $first->transition('publish');

    $second->title = 'Edited title';
    rescue(fn() => $second->transition('retract'), report: false);

    expect($second)
        ->title->toBe('Edited title')
        ->status->toBe(PostStatus::Ready)
        ->isDirty('title')->toBeTrue()
        ->isDirty('status')->toBeFalse();
});

it('does not dispatch Transitioned or Eloquent\'s updated events when the state is stale', function () {
    $post = Post::create()->transition('submit');
    $first = Post::find($post->getKey());
    $second = Post::find($post->getKey());
    $first->transition('publish');
    $fired = [];
    foreach (['updated', 'saved'] as $event) {
        Post::{$event}(function () use (&$fired, $event) {
            $fired[] = $event;
        });
    }
    Event::fake([Transitioned::class]);

    rescue(fn() => $second->transition('retract'), report: false);

    expect($fired)->toBe([]);
    Event::assertNotDispatched(Transitioned::class);
});

it('throws stale state when the row was deleted after the model was loaded', function () {
    $post = Post::create()->transition('submit');
    Post::find($post->getKey())->delete();

    expect(fn() => $post->transition('publish'))->toThrow(
        StaleStateException::class,
        'Cannot apply transition [publish] to ' . Post::class . ' [' . $post->getKey() . ']: its row no longer exists.',
    );
});

it('throws stale state that shares the Interlock base exception', function () {
    $post = Post::create()->transition('submit');
    Post::find($post->getKey())->transition('publish');

    expect(rescue(fn() => $post->transition('retract'), fn(Throwable $e) => $e, report: false))
        ->toBeInstanceOf(InterlockException::class);
});

it('applies a declared self-transition with timestamps off and nothing else changed', function () {
    $post = Post::create()->transition('submit')->transition('schedule');
    $post->timestamps = false;
    $fired = [];
    foreach (['updating', 'updated'] as $event) {
        Post::{$event}(function () use (&$fired, $event) {
            $fired[] = $event;
        });
    }
    Event::fake([Transitioned::class]);

    $post->transition('reschedule');

    expect($fired)->toBe(['updating', 'updated'])
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
    Event::assertDispatched(fn(Transitioned $event) => $event->model === $post
        && $event->transition === 'reschedule'
        && $event->fromState === PostStatus::Scheduled
        && $event->targetState === PostStatus::Scheduled);
});

it('throws stale state for a self-transition when the stored state changed', function () {
    $post = Post::create()->transition('submit')->transition('schedule');
    $post->timestamps = false;
    Post::find($post->getKey())->transition('publish');

    expect(fn() => $post->transition('reschedule'))->toThrow(StaleStateException::class);

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});

it('throws stale state when the update matched no row, even when the row reads as loaded afterwards', function () {
    $post = Post::create()->transition('submit');
    $restoreAfterTheWrite = false;
    // Another worker publishes the post just before the write. Afterwards a snapshot read, as under MySQL's REPEATABLE
    // READ, would still find it ready; here the stored state is put back right after the write instead.
    Event::listen(Transitioning::class, function () use ($post, &$restoreAfterTheWrite) {
        DB::table('posts')->where('id', $post->getKey())->update(['status' => PostStatus::Published->value]);
        $restoreAfterTheWrite = true;
    });
    DB::listen(function (QueryExecuted $query) use ($post, &$restoreAfterTheWrite) {
        if ($restoreAfterTheWrite && str_starts_with($query->sql, 'update')) {
            $restoreAfterTheWrite = false;
            DB::table('posts')->where('id', $post->getKey())->update(['status' => PostStatus::Ready->value]);
        }
    });
    Event::fake([Transitioned::class]);

    expect(fn() => $post->transition('publish'))->toThrow(StaleStateException::class);

    expect($post->history()->pluck('transition_name')->all())->toBe(['submit']);
    Event::assertNotDispatched(Transitioned::class);
});

it('throws stale state on MySQL when the transition\'s transaction read the row before another worker changed it', function (PostStatus $loadedState, string $transition, PostStatus $otherWorkersState) {
    // Two connections of their own, outside the test's transaction, so each sees what the other commits.
    $config = config('database.connections.' . DB::getDefaultConnection());
    config(['database.connections.worker' => $config, 'database.connections.other_worker' => $config]);
    DB::table('posts')->count(); // migrates the database, which the test's lazily refreshed connection does on its first query
    $otherWorker = DB::connection('other_worker');
    $id = $otherWorker->table('posts')->insertGetId(['status' => $loadedState->value, 'created_at' => now(), 'updated_at' => now()]);
    $history = fn() => $otherWorker->table('interlock_history')->where('model_type', (new Post())->getMorphClass())->where('model_id', $id);

    try {
        expect(fn() => DB::connection('worker')->transaction(function () use ($id, $transition, $otherWorker, $otherWorkersState) {
            // The transaction's first read takes its REPEATABLE READ snapshot, with the post in its loaded state.
            $post = Post::on('worker')->findOrFail($id);
            $otherWorker->table('posts')->where('id', $id)->update(['status' => $otherWorkersState->value]);

            $post->transition($transition);
        }))->toThrow(StaleStateException::class);

        expect($otherWorker->table('posts')->where('id', $id)->value('status'))->toBe($otherWorkersState->value)
            ->and($history()->exists())->toBeFalse();
    } finally {
        $history()->delete();
        $otherWorker->table('posts')->where('id', $id)->delete();
        DB::purge('worker');
        DB::purge('other_worker');
    }
})->with([
    'a transition' => [PostStatus::Ready, 'publish', PostStatus::Draft],
    'a self-transition' => [PostStatus::Scheduled, 'reschedule', PostStatus::Published],
])->skip(fn() => DB::connection()->getDriverName() !== 'mysql', 'Needs MySQL\'s REPEATABLE READ snapshots.');
