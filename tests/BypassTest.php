<?php

use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\BypassedWrite;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\InvalidBypassException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

it('saves a direct write to the status inside a bypass', function () {
    $post = Post::create(['status' => PostStatus::Draft]);

    Interlock::bypass('Fix posts stuck in draft', function () use ($post) {
        $post->update(['status' => PostStatus::Published]);
    });

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});

it('creates a post in a state that is not an initial state inside a bypass', function () {
    $post = Interlock::bypass('Import published posts', fn() => Post::create(['status' => PostStatus::Published]));

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});

it('requires a reason', function (string $reason) {
    expect(fn() => Interlock::bypass($reason, fn() => Post::create(['status' => PostStatus::Published])))->toThrow(
        InvalidBypassException::class,
        'A bypass needs a reason, such as "Import posts from the old blog", so the audit trail says why the state was written directly.',
    );

    expect(Post::count())->toBe(0);
})->with(['empty' => '', 'blank' => '   ']);

it('rejects direct writes again once the callback returns', function () {
    Interlock::bypass('Import published posts', fn() => null);

    expect(fn() => Post::create(['status' => PostStatus::Published]))->toThrow(DirectWriteException::class);
});

it('rejects direct writes again once the callback throws', function () {
    rescue(fn() => Interlock::bypass('Import published posts', fn() => throw new RuntimeException('Import failed')), report: false);

    expect(fn() => Post::create(['status' => PostStatus::Published]))->toThrow(DirectWriteException::class);
});

it('does not carry a bypass over to another app instance', function () {
    Interlock::bypass('Import published posts', function () {
        $this->refreshApplication();

        expect(fn() => Post::create(['status' => PostStatus::Published]))->toThrow(DirectWriteException::class);
    });
});

it('dispatches an event for each bypassed write, carrying the reason and the change', function () {
    Event::fake([BypassedWrite::class]);
    $post = Post::create(['status' => PostStatus::Draft]);

    Interlock::bypass('Fix posts stuck in draft', fn() => $post->update(['status' => PostStatus::Published]));

    Event::assertDispatchedTimes(BypassedWrite::class, 1);
    Event::assertDispatched(fn(BypassedWrite $event) => $event->reason === 'Fix posts stuck in draft'
        && $event->model->is($post)
        && $event->stateAttribute === 'status'
        && $event->oldState === PostStatus::Draft
        && $event->newState === PostStatus::Published);
});

it('dispatches an event when a post is created in a state that is not an initial state', function () {
    Event::fake([BypassedWrite::class]);

    $post = Interlock::bypass('Import published posts', fn() => Post::create(['status' => PostStatus::Published]));

    Event::assertDispatched(fn(BypassedWrite $event) => $event->reason === 'Import published posts'
        && $event->model->is($post)
        && $event->stateAttribute === 'status'
        && $event->oldState === null
        && $event->newState === PostStatus::Published);
});

it('dispatches the event when model events are muted', function (Closure $write) {
    Event::fake([BypassedWrite::class]);
    $post = Post::create(['status' => PostStatus::Draft]);

    Interlock::bypass('Fix posts stuck in draft', fn() => $write($post));

    expect($post->fresh()->status)->toBe(PostStatus::Published);
    Event::assertDispatchedTimes(BypassedWrite::class, 1);
})->with([
    'saveQuietly()' => fn(Post $post) => $post->fill(['status' => PostStatus::Published])->saveQuietly(),
    'updateQuietly()' => fn(Post $post) => $post->updateQuietly(['status' => PostStatus::Published]),
    'save() inside Model::withoutEvents()' => fn(Post $post) => Post::withoutEvents(
        fn() => $post->fill(['status' => PostStatus::Published])->save(),
    ),
]);

it('dispatches no event for writes inside a bypass that Interlock allows anyway', function () {
    Event::fake([BypassedWrite::class]);

    Interlock::bypass('Import drafts', function () {
        $post = Post::create(['status' => PostStatus::Draft]);
        $post->update(['title' => 'Changed']);
        $post->transition('submit');
    });

    Event::assertNotDispatched(BypassedWrite::class);
});

it('dispatches no event for a bypassed write that fails to save', function () {
    Event::fake([BypassedWrite::class]);

    rescue(fn() => Interlock::bypass('Import published posts', fn() => Post::create([
        'status' => PostStatus::Published,
        'column_that_does_not_exist' => 'fails the insert',
    ])), report: false);

    expect(Post::count())->toBe(0);
    Event::assertNotDispatched(BypassedWrite::class);
});

it('keeps the outer bypass active when a nested bypass ends', function () {
    $post = Interlock::bypass('Import published posts', function () {
        Interlock::bypass('Import drafts', fn() => null);

        return Post::create(['status' => PostStatus::Published]);
    });

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});

it('records the reason of the innermost bypass that is active', function () {
    Event::fake([BypassedWrite::class]);

    Interlock::bypass('Import published posts', function () {
        Interlock::bypass('Import scheduled posts', fn() => Post::create(['status' => PostStatus::Scheduled]));
        Post::create(['status' => PostStatus::Published]);
    });

    Event::assertDispatched(fn(BypassedWrite $event) => $event->newState === PostStatus::Scheduled && $event->reason === 'Import scheduled posts');
    Event::assertDispatched(fn(BypassedWrite $event) => $event->newState === PostStatus::Published && $event->reason === 'Import published posts');
});
