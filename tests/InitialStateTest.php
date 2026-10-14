<?php

use RobertBoes\Interlock\Exceptions\AmbiguousInitialStateException;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\InterlockException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\ImportedPost;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\ImportedPostStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatusMachine;

it('creates a post in its initial state', function () {
    $post = Post::create(['status' => PostStatus::Draft]);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

it('throws when a post is created in a state that is not an initial state, and writes nothing', function () {
    expect(fn() => Post::create(['status' => PostStatus::Published]))->toThrow(
        DirectWriteException::class,
        'Cannot create ' . Post::class . ' with [status] [published]: it is not an initial state of ' . PostStatusMachine::class . '. Its initial states are [draft].',
    );

    expect(Post::count())->toBe(0);
});

it('throws whichever create method writes the non-initial state', function (Closure $create) {
    expect($create)->toThrow(DirectWriteException::class);

    expect(Post::count())->toBe(0);
})->with([
    'create()' => fn() => Post::create(['status' => PostStatus::Published]),
    'createQuietly()' => fn() => Post::createQuietly(['status' => PostStatus::Published]),
    'save()' => fn() => (new Post(['status' => PostStatus::Published]))->save(),
    'saveQuietly()' => fn() => (new Post(['status' => PostStatus::Published]))->saveQuietly(),
    'create() inside Model::withoutEvents()' => fn() => Post::withoutEvents(fn() => Post::create(['status' => PostStatus::Published])),
]);

it('throws when a creating listener assigns a state that is not an initial state', function () {
    Post::creating(function (Post $post) {
        $post->status = PostStatus::Published;
    });

    expect(fn() => Post::create(['status' => PostStatus::Draft]))->toThrow(DirectWriteException::class);

    expect(Post::count())->toBe(0);
});

it('fills in the only initial state when a post is created without a status', function (Closure $create) {
    $post = $create();

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->fresh()->status)->toBe(PostStatus::Draft);
})->with([
    'create()' => fn() => Post::create(['title' => 'Untitled']),
    'createQuietly()' => fn() => Post::createQuietly(['title' => 'Untitled']),
]);

it('creates a post in any of several initial states', function (PostStatus $status) {
    $post = ImportedPost::create(['status' => $status]);

    expect($post->fresh()->status)->toBe($status);
})->with([PostStatus::Draft, PostStatus::Ready]);

it('throws when a post with several initial states is created without a status, and writes nothing', function () {
    expect(fn() => ImportedPost::create(['title' => 'Untitled']))->toThrow(
        AmbiguousInitialStateException::class,
        'Cannot create ' . ImportedPost::class . ' without a [status]: ' . ImportedPostStatusMachine::class . ' has several initial states [draft, ready]. Set the one it starts in.',
    );

    expect(ImportedPost::count())->toBe(0);
});

it('throws exceptions that share the Interlock base exception', function (Closure $create) {
    expect(thrownBy($create))
        ->toBeInstanceOf(InterlockException::class);
})->with([
    'not an initial state' => fn() => Post::create(['status' => PostStatus::Published]),
    'ambiguous initial state' => fn() => ImportedPost::create(['title' => 'Untitled']),
]);
