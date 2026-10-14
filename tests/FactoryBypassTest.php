<?php

use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\BypassedWrite;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

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
