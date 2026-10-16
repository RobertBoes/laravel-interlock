<?php

use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Bypass\BypassScope;
use RobertBoes\Interlock\Events\BypassedWrite;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

function runInProduction(): void
{
    // LazilyRefreshDatabase migrates on the first query, which migrate refuses in production.
    Post::query()->exists();
    app()['env'] = 'production';
    app()->forgetInstance(BypassScope::class);
}

it('creates a post in any state through its factory', function (PostStatus $status) {
    $post = Post::factory()->create(['status' => $status]);

    expect($post->fresh()->status)->toBe($status);
})->with(PostStatus::cases());

it('records a factory write as a bypass with reason "factory"', function () {
    Event::fake([BypassedWrite::class]);

    $post = Post::factory()->create(['status' => PostStatus::Published]);

    Event::assertDispatched(fn(BypassedWrite $event) => $event->reason === 'factory'
        && $event->model->is($post)
        && $event->stateAttribute === 'status'
        && $event->oldState === null
        && $event->newState === PostStatus::Published);
});

it('creates several posts in any state through their factory', function () {
    $posts = Post::factory()->count(2)->create(['status' => PostStatus::Scheduled]);

    expect($posts->map->fresh()->pluck('status')->all())->toBe([PostStatus::Scheduled, PostStatus::Scheduled]);
});

it('rejects a post made by its factory and saved outside it', function () {
    $post = Post::factory()->make(['status' => PostStatus::Published]);

    expect(fn() => $post->save())->toThrow(DirectWriteException::class);

    expect(Post::count())->toBe(0);
});

it('rejects a direct write to a post created by its factory', function () {
    $post = Post::factory()->create(['status' => PostStatus::Draft]);
    $post->status = PostStatus::Published;

    expect(fn() => $post->save())->toThrow(DirectWriteException::class);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

it('rejects a factory write in production, where a factory is no test fixture', function () {
    runInProduction();

    expect(fn() => Post::factory()->create(['status' => PostStatus::Published]))->toThrow(DirectWriteException::class);

    expect(Post::count())->toBe(0);
});

it('lets a factory write in production through inside a bypass', function () {
    runInProduction();

    $post = Interlock::bypass('Seed the demo tenant', fn() => Post::factory()->create(['status' => PostStatus::Published]));

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});
