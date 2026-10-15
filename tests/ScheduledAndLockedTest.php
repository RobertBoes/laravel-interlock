<?php

use Illuminate\Support\Facades\Artisan;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\TriggerOnlyTransitionException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\LockedPosts\LockedPost;
use RobertBoes\Interlock\Tests\Fixtures\LockedPosts\LockedPostStatusMachine;

/**
 * The "scheduled and locked" pattern the README documents: a state whose only way out is the triggered transition.
 */
beforeEach(fn() => config([
    'interlock.discovery.paths' => [],
    'interlock.models' => [LockedPost::class],
]));

function lockedPostScheduledIn(int $hours): LockedPost
{
    $post = LockedPost::create()->transition('submit');
    $post->publish_at = now()->addHours($hours);

    return $post->transition('schedule');
}

it('is a sound machine', function () {
    Interlock::assertMachinesAreSound(LockedPost::class);
});

it('offers no way out of scheduled, as only the trigger publishes', function () {
    $post = lockedPostScheduledIn(1);

    expect($post->availableTransitions(null))->toBeEmpty()
        ->and($post->availableTransitions(Employee::manager()))->toBeEmpty()
        ->and(fn() => $post->transition('publish', Employee::manager()))->toThrow(TriggerOnlyTransitionException::class);
});

it('keeps the publish time of a scheduled post fixed', function () {
    $post = lockedPostScheduledIn(1);
    $post->publish_at = now()->addDay();

    expect(fn() => $post->save())->toThrow(DirectWriteException::class);
});

it('exports as the Mermaid diagram the README shows for it', function () {
    preg_match('/```mermaid\n(.*?)```/s', file_get_contents(__DIR__ . '/../README.md'), $diagram);

    expect(Artisan::call('interlock:signalbox', ['machine' => LockedPostStatusMachine::class]))->toBe(0)
        ->and(Artisan::output())->toBe($diagram[1]);
});

it('publishes the scheduled post once its time has passed, as long as the scheduler runs', function () {
    $post = lockedPostScheduledIn(1);

    $this->artisan('interlock:triggers');
    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);

    $this->travel(2)->hours();
    $this->artisan('interlock:triggers');
    expect($post->fresh()->status)->toBe(PostStatus::Published);
});
