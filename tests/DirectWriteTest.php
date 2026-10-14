<?php

use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\InterlockException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

it('throws when a post whose status was assigned directly is saved, and writes nothing', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    $post->status = PostStatus::Published;
    $post->title = 'Skipping the queue';

    expect(fn() => $post->save())->toThrow(
        DirectWriteException::class,
        'Cannot save ' . Post::class . ' [' . $post->getKey() . ']: its [status] was changed from [draft] to [published] outside a transition. Apply a transition instead.',
    );

    expect($post->fresh())
        ->status->toBe(PostStatus::Draft)
        ->title->toBeNull();
});

it('throws whichever save method writes the direct assignment, and writes nothing', function (Closure $save) {
    $post = Post::create(['status' => PostStatus::Draft, 'title' => 'Original']);

    expect(fn() => $save($post))->toThrow(DirectWriteException::class);

    expect($post->fresh())
        ->status->toBe(PostStatus::Draft)
        ->title->toBe('Original');
})->with([
    'save()' => fn(Post $post) => $post->fill(['status' => PostStatus::Published, 'title' => 'Changed'])->save(),
    'saveQuietly()' => fn(Post $post) => $post->fill(['status' => PostStatus::Published, 'title' => 'Changed'])->saveQuietly(),
    'update()' => fn(Post $post) => $post->update(['status' => PostStatus::Published, 'title' => 'Changed']),
    'updateQuietly()' => fn(Post $post) => $post->updateQuietly(['status' => PostStatus::Published, 'title' => 'Changed']),
    'save() inside Model::withoutEvents()' => fn(Post $post) => Post::withoutEvents(
        fn() => $post->fill(['status' => PostStatus::Published, 'title' => 'Changed'])->save(),
    ),
]);

it('throws when an updating listener assigns the status directly', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    Post::updating(function (Post $post) {
        $post->status = PostStatus::Published;
    });

    expect(fn() => $post->update(['title' => 'Changed']))->toThrow(DirectWriteException::class);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

it('throws when a listener replaces the target state while a transition saves', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    Post::saving(function (Post $post) {
        $post->status = PostStatus::Published;
    });

    expect(fn() => $post->transition('submit'))->toThrow(DirectWriteException::class);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

it('saves changes to other attributes as usual', function () {
    $post = Post::create(['status' => PostStatus::Draft]);

    $post->update(['title' => 'Changed']);

    expect($post->fresh())
        ->status->toBe(PostStatus::Draft)
        ->title->toBe('Changed');
});

it('saves an assignment of the state the post is already in', function () {
    $post = Post::create(['status' => PostStatus::Draft]);

    $post->update(['status' => PostStatus::Draft, 'title' => 'Changed']);

    expect($post->fresh()->title)->toBe('Changed');
});

it('has no config option that turns enforcement off', function () {
    config(['interlock' => ['enabled' => false, 'enforce' => false, 'strict' => false, 'mode' => 'log']]);
    $post = Post::create(['status' => PostStatus::Draft]);
    $post->status = PostStatus::Published;

    expect(fn() => $post->save())->toThrow(DirectWriteException::class)
        ->and(fn() => Post::create(['status' => PostStatus::Published]))->toThrow(DirectWriteException::class);
});

it('is an Interlock exception', function () {
    $post = Post::create(['status' => PostStatus::Draft]);
    $post->status = PostStatus::Published;

    expect(rescue(fn() => $post->save(), fn(Throwable $e) => $e, report: false))
        ->toBeInstanceOf(InterlockException::class);
});
