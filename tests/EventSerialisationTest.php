<?php

use Illuminate\Support\Facades\DB;
use RobertBoes\Interlock\Events\BypassedGuardedAttributeWrite;
use RobertBoes\Interlock\Events\BypassedWrite;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

/**
 * Serialises the event the way the queue does for a queued listener, after the row changed behind the model's back.
 *
 * @template TEvent of object
 *
 * @param  Closure(Post): TEvent  $event
 * @return array{Post, TEvent}
 */
function queuedRoundTrip(Closure $event): array
{
    $post = Post::create(['title' => 'Stale title', 'status' => PostStatus::Draft]);
    $serialised = serialize($event($post));

    DB::table('posts')->where('id', $post->getKey())->update(['title' => 'Fresh title']);

    return [$post, unserialize($serialised)];
}

it('re-fetches the model by key when a queued listener receives the event', function (Closure $event) {
    [$post, $restored] = queuedRoundTrip($event);

    expect($restored->model)
        ->toBeInstanceOf(Post::class)
        ->not->toBe($post)
        ->getKey()->toBe($post->getKey())
        ->title->toBe('Fresh title');
})->with([
    'Transitioning' => fn(Post $post) => new Transitioning($post, 'publish', PostStatus::Ready, PostStatus::Published),
    'Transitioned' => fn(Post $post) => new Transitioned($post, 'publish', PostStatus::Ready, PostStatus::Published),
    'BypassedWrite' => fn(Post $post) => new BypassedWrite('Backfill', $post, 'status', PostStatus::Draft, PostStatus::Ready),
    'BypassedGuardedAttributeWrite' => fn(Post $post) => new BypassedGuardedAttributeWrite('Backfill', $post, 'title', 'Old title', 'Stale title'),
]);

it('keeps the event\'s other properties when a queued listener receives it', function () {
    [, $transitioned] = queuedRoundTrip(fn(Post $post) => new Transitioned($post, 'publish', PostStatus::Ready, PostStatus::Published));
    [, $bypassedWrite] = queuedRoundTrip(fn(Post $post) => new BypassedWrite('Backfill', $post, 'status', PostStatus::Draft, PostStatus::Ready));
    [, $guardedWrite] = queuedRoundTrip(fn(Post $post) => new BypassedGuardedAttributeWrite('Backfill', $post, 'title', 'Old title', 'Stale title'));

    expect($transitioned)
        ->transition->toBe('publish')
        ->fromState->toBe(PostStatus::Ready)
        ->targetState->toBe(PostStatus::Published)
        ->and($bypassedWrite)
        ->reason->toBe('Backfill')
        ->stateAttribute->toBe('status')
        ->oldState->toBe(PostStatus::Draft)
        ->newState->toBe(PostStatus::Ready)
        ->and($guardedWrite)
        ->attribute->toBe('title')
        ->oldValue->toBe('Old title')
        ->newValue->toBe('Stale title');
});

it('serialises the model by key, not as a snapshot', function () {
    $post = Post::create(['title' => 'Stale title', 'status' => PostStatus::Draft]);

    expect(serialize(new Transitioned($post, 'publish', PostStatus::Ready, PostStatus::Published)))
        ->not->toContain('Stale title');
});
