<?php

use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\BypassedWrite;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\InvalidBypassException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

it('bypasses for the whole test once the test opts in', function () {
    Interlock::bypassForTest();

    $post = Post::create(['status' => PostStatus::Draft]);
    $post->update(['status' => PostStatus::Published]);
    $scheduled = Post::create(['status' => PostStatus::Scheduled]);

    expect($post->fresh()->status)->toBe(PostStatus::Published)
        ->and($scheduled->fresh()->status)->toBe(PostStatus::Scheduled);
});

it('records writes in an opted-in test as bypasses with reason "test", or the reason given', function (array $arguments, string $reason) {
    Event::fake([BypassedWrite::class]);

    Interlock::bypassForTest(...$arguments);
    Post::create(['status' => PostStatus::Published]);

    Event::assertDispatched(fn(BypassedWrite $event) => $event->reason === $reason);
})->with([
    'no reason' => [[], 'test'],
    'a reason' => [['Testing the archive page'], 'Testing the archive page'],
]);

it('lets a bypass inside an opted-in test record its own reason, and keeps bypassing after it ends', function () {
    Event::fake([BypassedWrite::class]);
    Interlock::bypassForTest();

    Interlock::bypass('Import scheduled posts', fn() => Post::create(['status' => PostStatus::Scheduled]));
    Post::create(['status' => PostStatus::Published]);

    Event::assertDispatched(fn(BypassedWrite $event) => $event->newState === PostStatus::Scheduled && $event->reason === 'Import scheduled posts');
    Event::assertDispatched(fn(BypassedWrite $event) => $event->newState === PostStatus::Published && $event->reason === 'test');
});

it('enforces tests that do not opt in', function () {
    expect(fn() => Post::create(['status' => PostStatus::Published]))->toThrow(DirectWriteException::class);
});

it('ends the opt-in with the test, so the next test starts enforced', function () {
    Interlock::bypassForTest();

    $this->refreshApplication();

    expect(fn() => Post::create(['status' => PostStatus::Published]))->toThrow(DirectWriteException::class);
});

it('requires a reason when one is given', function () {
    expect(fn() => Interlock::bypassForTest(' '))->toThrow(InvalidBypassException::class);
});

it('refuses to bypass for a test outside the test environment', function () {
    $this->app['env'] = 'production';

    expect(fn() => Interlock::bypassForTest())->toThrow(
        InvalidBypassException::class,
        'Interlock::bypassForTest() only works while running tests. Wrap the writes in Interlock::bypass($reason, $callback) instead.',
    );

    expect(fn() => Post::create(['status' => PostStatus::Published]))->toThrow(DirectWriteException::class);
});
