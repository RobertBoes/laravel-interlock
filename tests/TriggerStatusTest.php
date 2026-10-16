<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Expense;
use RobertBoes\Interlock\Tests\Fixtures\Feeds\FeedPost;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Triggers\RunTrigger;
use RobertBoes\Interlock\Triggers\RunTriggerChunk;
use RobertBoes\Interlock\Triggers\TriggerStatus;

beforeEach(fn() => config([
    'interlock.discovery.paths' => [],
    'interlock.models' => [Post::class],
    'queue.default' => 'sync',
]));

function postTriggerStatus(): TriggerStatus
{
    $statuses = array_values(array_filter(Interlock::triggerStatus(), fn(TriggerStatus $status) => $status->model === Post::class));

    expect($statuses)->toHaveCount(1);

    return $statuses[0];
}

function postDueAt(string $publishAt): Post
{
    return Post::create(['publish_at' => $publishAt])->transition('submit');
}

function breakPublishing(Post $post): void
{
    Event::listen(Transitioning::class, fn(Transitioning $event) => $event->model->is($post) ? throw new RuntimeException('The search index is down.') : null);
}

describe('a trigger run', function () {
    it('records when it finished', function () {
        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect(postTriggerStatus()->lastRunAt?->toDateTimeString())->toBe('2026-10-14 09:00:00');

        $this->travelTo('2026-10-14 09:01:00');
        $this->artisan('interlock:triggers');

        expect(postTriggerStatus()->lastRunAt?->toDateTimeString())->toBe('2026-10-14 09:01:00');
    });

    it('records since when models have matched, until a run leaves none matching', function () {
        Exceptions::fake();
        $post = postDueAt('2026-10-14 09:00:00');
        breakPublishing($post);

        $this->travelTo('2026-10-14 08:59:00');
        $this->artisan('interlock:triggers');

        expect(postTriggerStatus()->matchingSince)->toBeNull();

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        $this->travelTo('2026-10-14 09:05:00');
        $this->artisan('interlock:triggers');

        expect(postTriggerStatus()->matchingSince?->toDateTimeString())->toBe('2026-10-14 09:00:00');

        Event::forget(Transitioning::class);
        $this->travelTo('2026-10-14 09:06:00');
        $this->artisan('interlock:triggers');

        expect($post->fresh()->status)->toBe(PostStatus::Published)
            ->and(postTriggerStatus()->matchingSince)->toBeNull();
    });

    it('is never overdue while every run applies the models that became due since the last', function () {
        $this->travelTo('2026-10-14 09:00:00');

        foreach (range(0, 20) as $minute) {
            $this->travelTo(now()->setTime(9, $minute));
            postDueAt(now()->toDateTimeString());
            $this->artisan('interlock:triggers');
        }

        expect(Post::query()->where('status', PostStatus::Published)->count())->toBe(21)
            ->and(postTriggerStatus()->matchingSince)->toBeNull()
            ->and(postTriggerStatus()->overdue)->toBeFalse();
    });

    it('records no finish when it fails', function () {
        Exceptions::fake();
        postDueAt('2026-10-14 09:00:00');
        Event::listen(Transitioning::class, fn() => throw new QueryException('testing', 'update "posts" set "status" = ?', ['published'], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')));

        $this->travelTo('2026-10-14 09:00:00');
        rescue(fn() => app()->call([new RunTrigger(Post::class, 'publish'), 'handle']), report: false);

        expect(postTriggerStatus()->lastRunAt)->toBeNull()
            ->and(postTriggerStatus()->matchingSince?->toDateTimeString())->toBe('2026-10-14 09:00:00');
    });

    it('clears a fanned-out trigger once its last chunk job leaves none matching', function () {
        config(['interlock.models' => [FeedPost::class], 'interlock.triggers.chunk_size' => 1]);
        FeedPost::create(['publish_at' => '2026-10-14 09:00:00'])->transition('submit');
        FeedPost::create(['publish_at' => '2026-10-14 09:00:00'])->transition('submit');

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect(Interlock::triggerStatus()[0]->matching)->toBe(0)
            ->and(Interlock::triggerStatus()[0]->matchingSince)->toBeNull();
    });

    it('counts a trigger that fans out as running once its dispatching run finishes, while its chunk jobs are pending', function () {
        config(['interlock.models' => [FeedPost::class], 'interlock.triggers.chunk_size' => 1, 'interlock.triggers.timeout' => 600]);
        Queue::fake([RunTriggerChunk::class]);
        FeedPost::create(['publish_at' => '2026-10-14 09:00:00'])->transition('submit');
        FeedPost::create(['publish_at' => '2026-10-14 09:00:00'])->transition('submit');

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect(Interlock::triggerStatus()[0]->lastRunAt?->toDateTimeString())->toBe('2026-10-14 09:00:00');

        $this->travelTo('2026-10-14 09:14:00');
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 2);
        expect(Interlock::triggerStatus()[0]->lastRunAt?->toDateTimeString())->toBe('2026-10-14 09:14:00')
            ->and(Interlock::triggerStatus()[0]->overdue)->toBeFalse();
    });
});

describe('the trigger status', function () {
    it('has one entry per trigger, counting the models that match it now in its from-states', function () {
        config(['interlock.models' => [Post::class, Expense::class, Order::class]]);
        $this->travelTo('2026-10-01 12:00:00');
        postDueAt('2026-10-01 09:00:00');
        postDueAt('2026-10-01 09:00:00');
        postDueAt('2026-10-02 09:00:00');
        Post::create(['publish_at' => '2026-10-01 09:00:00']);
        Order::create();

        $statuses = array_map(fn(TriggerStatus $status) => [$status->model, $status->transition, $status->matching, $status->lastRunAt, $status->matchingSince, $status->overdue], Interlock::triggerStatus());

        expect($statuses)->toBe([
            [Post::class, 'publish', 2, null, null, true],
            [Expense::class, 'submit', 0, null, null, false],
            [Order::class, 'cancel', 0, null, null, false],
        ]);
    });

    it('is not overdue when no model matches, even when it has never run', function () {
        postDueAt('2026-10-14 09:00:00');
        $this->travelTo('2026-10-14 08:00:00');

        expect(postTriggerStatus())
            ->matching->toBe(0)
            ->overdue->toBeFalse();
    });

    it('is overdue when models match and no run has finished', function () {
        postDueAt('2026-10-14 09:00:00');
        $this->travelTo('2026-10-14 09:00:00');

        expect(postTriggerStatus())
            ->matching->toBe(1)
            ->overdue->toBeTrue();
    });

    it('is overdue when models match and the last run finished longer ago than the threshold', function () {
        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        postDueAt('2026-10-14 09:00:00');

        $this->travelTo('2026-10-14 09:15:00');

        expect(postTriggerStatus()->overdue)->toBeFalse();

        $this->travelTo('2026-10-14 09:15:01');

        expect(postTriggerStatus()->overdue)->toBeTrue();
    });

    it('is overdue when models have matched at the start of every run for longer than the threshold', function () {
        Exceptions::fake();
        breakPublishing(postDueAt('2026-10-14 09:00:00'));

        $overdue = [];
        foreach (range(0, 16) as $minute) {
            $this->travelTo(sprintf('2026-10-14 09:%02d:00', $minute));
            $this->artisan('interlock:triggers');
            $overdue[$minute] = postTriggerStatus()->overdue;
        }

        expect(array_keys(array_filter($overdue)))->toBe([16])
            ->and(postTriggerStatus()->lastRunAt?->toDateTimeString())->toBe('2026-10-14 09:16:00');
    });

    it('takes its threshold from the config', function () {
        config(['interlock.triggers.overdue_after' => 5]);
        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        postDueAt('2026-10-14 09:00:00');

        $this->travelTo('2026-10-14 09:05:01');

        expect(postTriggerStatus()->overdue)->toBeTrue();
    });

    it('uses a threshold of 15 minutes when a published config predates the key', function () {
        config(['interlock.triggers' => ['chunk_size' => 100, 'max_per_run' => 1000]]);
        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        postDueAt('2026-10-14 09:00:00');

        $this->travelTo('2026-10-14 09:15:00');

        expect(postTriggerStatus()->overdue)->toBeFalse();

        $this->travelTo('2026-10-14 09:15:01');

        expect(postTriggerStatus()->overdue)->toBeTrue();
    });
});

describe('the triggers command with --status', function () {
    it('prints each trigger\'s status as a table, without dispatching or recording anything', function () {
        config(['interlock.models' => [Post::class, Expense::class]]);
        Queue::fake();
        $this->travelTo('2026-10-14 09:00:00');
        postDueAt('2026-10-14 09:00:00');

        $this->artisan('interlock:triggers --status')
            ->expectsTable(
                ['Model', 'Transition', 'Matching', 'Last run', 'Matching since', 'Overdue'],
                [
                    [Post::class, 'publish', '1', 'never', '-', 'yes'],
                    [Expense::class, 'submit', '0', 'never', '-', 'no'],
                ],
            )
            ->assertSuccessful();

        Queue::assertNothingPushed();
        expect(postTriggerStatus()->lastRunAt)->toBeNull();
    });

    it('prints when the last run finished and since when models have matched', function () {
        Exceptions::fake();
        breakPublishing(postDueAt('2026-10-14 09:00:00'));
        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        $this->travelTo('2026-10-14 09:01:00');
        $this->artisan('interlock:triggers');

        $this->artisan('interlock:triggers --status')
            ->expectsTable(
                ['Model', 'Transition', 'Matching', 'Last run', 'Matching since', 'Overdue'],
                [[Post::class, 'publish', '1', '2026-10-14 09:01:00', '2026-10-14 09:00:00', 'no']],
            );
    });
});

describe('the trigger check', function () {
    it('passes when no trigger is overdue', function () {
        config(['interlock.models' => [Post::class, Expense::class]]);
        postDueAt('2026-10-14 09:00:00');
        $this->travelTo('2026-10-14 08:00:00');

        $this->artisan('interlock:triggers:check')
            ->expectsOutputToContain('No trigger is overdue.')
            ->assertSuccessful();
    });

    it('fails and names each overdue trigger', function () {
        config(['interlock.models' => [Post::class, Expense::class, Order::class]]);
        $this->travelTo('2026-10-01 12:00:00');
        Order::create();
        $this->artisan('interlock:triggers:check')->assertSuccessful();
        postDueAt('2026-10-01 09:00:00');
        $this->travelTo('2026-10-04 12:00:00');

        $this->artisan('interlock:triggers:check')
            ->expectsOutputToContain('Trigger [publish] of [' . Post::class . '] is overdue: 1 model matches it, and no run has finished.')
            ->expectsOutputToContain('Trigger [cancel] of [' . Order::class . '] is overdue: 1 model matches it, and no run has finished.')
            ->doesntExpectOutputToContain(Expense::class)
            ->assertFailed();
    });

    it('says when the last run finished and since when models have matched', function () {
        Exceptions::fake();
        breakPublishing(postDueAt('2026-10-14 09:00:00'));
        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        $this->travelTo('2026-10-14 09:20:00');
        $this->artisan('interlock:triggers');

        $this->artisan('interlock:triggers:check')
            ->expectsOutputToContain('Trigger [publish] of [' . Post::class . '] is overdue: 1 model matches it, the last run finished at 2026-10-14 09:20:00, and models have matched it since 2026-10-14 09:00:00.')
            ->assertFailed();
    });

    it('changes nothing', function () {
        Queue::fake();
        postDueAt('2026-10-14 09:00:00');
        $this->travelTo('2026-10-14 09:00:00');

        $this->artisan('interlock:triggers:check')->assertFailed();

        Queue::assertNothingPushed();
        expect(postTriggerStatus()->lastRunAt)->toBeNull();
    });
});
