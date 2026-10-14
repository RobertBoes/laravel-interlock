<?php

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Expense;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\ExpenseStatus;
use RobertBoes\Interlock\Tests\Fixtures\Feeds\FeedPost;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithInstanceTrigger;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTriggeredTransitionTakingInput;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TransitionWithInputAndTrigger;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TransitionWithInstanceTrigger;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Ship;
use RobertBoes\Interlock\Triggers\RunTrigger;
use RobertBoes\Interlock\Triggers\RunTriggerChunk;

it('runs the triggers every minute once the scheduler runs', function () {
    $events = array_filter(
        app(Schedule::class)->events(),
        fn(ScheduledEvent $event) => str_contains((string) $event->command, 'interlock:triggers'),
    );

    expect($events)->toHaveCount(1)
        ->and(array_values($events)[0]->expression)->toBe('* * * * *');
});

it('rejects a transition class whose trigger is not static', function () {
    expect(fn() => (new PostWithInstanceTrigger())->availableTransitions(null))->toThrow(
        InvalidDefinitionException::class,
        'Transition ' . TransitionWithInstanceTrigger::class . ' declares trigger() as an instance method. Make it static: a trigger is a query condition, checked without building the transition.',
    );
});

it('rejects a trigger on a transition class that takes input', function () {
    expect(fn() => (new PostWithTriggeredTransitionTakingInput())->availableTransitions(null))->toThrow(
        InvalidDefinitionException::class,
        'Transition ' . TransitionWithInputAndTrigger::class . ' declares a trigger, but its constructor takes input. A trigger applies the transition by name, without input: remove the trigger, or give the constructor parameters defaults.',
    );
});

describe('the triggers command', function () {
    beforeEach(fn() => config([
        'interlock.discovery.paths' => [],
        'interlock.models' => [Post::class],
    ]));

    it('dispatches one job per trigger', function () {
        Queue::fake();

        $this->artisan('interlock:triggers')->assertSuccessful();

        Queue::assertPushed(RunTrigger::class, 1);
        Queue::assertPushed(fn(RunTrigger $job) => $job->model === Post::class && $job->trigger === 'publish');
    });

    it('dispatches the jobs of every registered model\'s triggers, without querying anything', function () {
        config(['interlock.models' => [Post::class, Expense::class, Order::class]]);
        Queue::fake();
        DB::enableQueryLog();

        $this->artisan('interlock:triggers')->assertSuccessful();

        expect(DB::getQueryLog())->toBe([]);
        Queue::assertPushed(RunTrigger::class, 3);
        Queue::assertPushed(fn(RunTrigger $job) => $job->model === Expense::class && $job->trigger === 'submit');
        Queue::assertPushed(fn(RunTrigger $job) => $job->model === Order::class && $job->trigger === 'cancel');
    });

    it('does not dispatch a trigger again while its job has not finished', function () {
        Queue::fake();

        $this->artisan('interlock:triggers');
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTrigger::class, 1);
    });
});

describe('a trigger', function () {
    beforeEach(fn() => config([
        'interlock.discovery.paths' => [],
        'interlock.models' => [Post::class],
        'queue.default' => 'sync',
    ]));

    it('publishes a post at its scheduled time', function () {
        $post = scheduledPost('2026-10-14 09:00:00');

        $this->travelTo('2026-10-14 08:59:59');
        $this->artisan('interlock:triggers');

        expect($post->fresh()->status)->toBe(PostStatus::Ready);

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect($post->fresh()->status)->toBe(PostStatus::Published);
    });

    it('works through the matching models in chunks, in key order', function () {
        config(['interlock.triggers.chunk_size' => 2]);
        $posts = collect(range(1, 5))->map(fn() => scheduledPost('2026-10-14 09:00:00'));
        $published = [];
        Event::listen(Transitioned::class, function (Transitioned $event) use (&$published) {
            $published[] = $event->model->getKey();
        });

        $this->travelTo('2026-10-14 09:00:00');
        DB::enableQueryLog();
        $this->artisan('interlock:triggers');

        $chunkQueries = array_filter(DB::getQueryLog(), fn(array $query) => str_starts_with($query['query'], 'select') && str_contains($query['query'], 'limit 2'));
        expect($published)->toBe($posts->map->getKey()->all())
            ->and($chunkQueries)->toHaveCount(3);
    });

    it('applies the transition to at most the configured number of models per run', function () {
        config(['interlock.triggers.chunk_size' => 2, 'interlock.triggers.max_per_run' => 3]);
        $posts = collect(range(1, 5))->map(fn() => scheduledPost('2026-10-14 09:00:00'));

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect($posts->map(fn(Post $post) => $post->fresh()->status)->all())->toBe([
            PostStatus::Published,
            PostStatus::Published,
            PostStatus::Published,
            PostStatus::Ready,
            PostStatus::Ready,
        ]);
    });

    it('drains a backlog bigger than the cap over successive runs', function () {
        config(['interlock.triggers.max_per_run' => 2]);
        $posts = collect(range(1, 5))->map(fn() => scheduledPost('2026-10-14 09:00:00'));
        $this->travelTo('2026-10-14 09:00:00');

        $published = [];
        foreach (range(1, 3) as $run) {
            $this->artisan('interlock:triggers');
            $published[$run] = Post::query()->where('status', PostStatus::Published)->count();
        }

        expect($published)->toBe([1 => 2, 2 => 4, 3 => 5]);
    });

    it('drains the backlog past models that are skipped on every run', function () {
        config(['interlock.models' => [Expense::class], 'interlock.triggers.max_per_run' => 2]);
        Exceptions::fake();
        $this->travelTo('2026-09-01 12:00:00');
        $withoutAmount = collect(range(1, 2))->map(fn() => Expense::create());
        $withAmount = collect(range(1, 3))->map(fn() => Expense::create(['amount' => 4_500]));

        $this->travelTo('2026-10-01 12:00:00');
        foreach (range(1, 3) as $minute) {
            $this->artisan('interlock:triggers');
            $this->travel(1)->minute();
        }

        expect($withoutAmount->map(fn(Expense $expense) => $expense->fresh()->status)->unique()->all())->toBe([ExpenseStatus::Draft])
            ->and($withAmount->map(fn(Expense $expense) => $expense->fresh()->status)->unique()->all())->toBe([ExpenseStatus::Submitted]);
    });

    it('leaves a post that was moved back to draft before its time, without cancelling anything', function () {
        $post = scheduledPost('2026-10-14 09:00:00');
        $post->transition('retract');

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect($post->fresh()->status)->toBe(PostStatus::Draft);
    });

    it('reschedules a post when its publish_at changes', function () {
        $post = scheduledPost('2026-10-14 09:00:00');
        $post->update(['publish_at' => '2026-10-15 09:00:00']);

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect($post->fresh()->status)->toBe(PostStatus::Ready);

        $this->travelTo('2026-10-15 09:00:00');
        $this->artisan('interlock:triggers');

        expect($post->fresh()->status)->toBe(PostStatus::Published);
    });

    it('records the trigger in history, on behalf of no one even when a user is signed in', function () {
        $post = scheduledPost('2026-10-14 09:00:00');
        $this->actingAs(Employee::manager());

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect($post->history()->latest('id')->first())
            ->transition_name->toBe('publish')
            ->trigger_name->toBe('publish')
            ->actor_type->toBeNull()
            ->actor_id->toBeNull();
    });

    it('records no trigger for a transition applied by hand', function () {
        $post = scheduledPost('2026-10-14 09:00:00');

        $post->transition('publish');

        expect($post->history()->latest('id')->first()->trigger_name)->toBeNull();
    });

    it('reports a model that throws, carries on with the rest, and picks it up again on the next run', function () {
        Exceptions::fake();
        $broken = scheduledPost('2026-10-14 09:00:00');
        $healthy = scheduledPost('2026-10-14 09:00:00');
        Event::listen(Transitioning::class, fn(Transitioning $event) => $event->model->is($broken) ? throw new RuntimeException('The search index is down.') : null);

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        Exceptions::assertReported(fn(RuntimeException $exception) => $exception->getMessage() === 'The search index is down.');
        expect($broken->fresh()->status)->toBe(PostStatus::Ready)
            ->and($healthy->fresh()->status)->toBe(PostStatus::Published);

        Event::forget(Transitioning::class);
        $this->travelTo('2026-10-14 09:01:00');
        $this->artisan('interlock:triggers');

        expect($broken->fresh()->status)->toBe(PostStatus::Published);
    });

    it('skips a model whose state changed after it was loaded, without reporting it', function () {
        Exceptions::fake();
        $changed = scheduledPost('2026-10-14 09:00:00');
        $unchanged = scheduledPost('2026-10-14 09:00:00');
        Post::retrieved(function (Post $post) use ($changed) {
            if ($post->is($changed)) {
                DB::table('posts')->where('id', $post->getKey())->update(['status' => PostStatus::Draft->value]);
            }
        });

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        Exceptions::assertNothingReported();
        expect($changed->fresh()->status)->toBe(PostStatus::Draft)
            ->and($unchanged->fresh()->status)->toBe(PostStatus::Published);
    });

    it('applies a class-based transition\'s trigger, from its from-states only', function () {
        config(['interlock.models' => [Order::class]]);
        $this->travelTo('2026-10-01 12:00:00');
        $pending = Order::create();
        $packing = Order::create()->transition('prepare');
        $shipped = orderReadyToShip()->transition(new Ship('TRACK-1'));
        $paid = Order::create()->transition('authorise_payment')->transition('capture_payment');

        $this->travelTo('2026-10-04 12:00:00');
        $this->artisan('interlock:triggers');

        expect($pending->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($packing->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($shipped->fresh()->status)->toBe(OrderStatus::Shipped)
            ->and($paid->fresh()->status)->toBe(OrderStatus::Pending);
    });

    it('skips a model whose guard fails, without reporting it', function () {
        config(['interlock.models' => [Expense::class]]);
        Exceptions::fake();
        $this->travelTo('2026-09-01 12:00:00');
        $withoutAmount = Expense::create();
        $withAmount = Expense::create(['amount' => 4_500]);

        $this->travelTo('2026-10-01 12:00:00');
        $this->artisan('interlock:triggers');

        Exceptions::assertNothingReported();
        expect($withoutAmount->fresh()->status)->toBe(ExpenseStatus::Draft)
            ->and($withAmount->fresh()->status)->toBe(ExpenseStatus::Submitted);
    });
});

describe('a trigger that fans out', function () {
    beforeEach(fn() => config([
        'interlock.discovery.paths' => [],
        'interlock.models' => [FeedPost::class],
        'interlock.triggers.chunk_size' => 2,
        'queue.default' => 'sync',
    ]));

    it('dispatches one job per chunk of matching models, in key order, instead of applying the transition itself', function () {
        Queue::fake([RunTriggerChunk::class]);
        $posts = collect(range(1, 5))->map(fn() => scheduledFeedPost('2026-10-14 09:00:00'));

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect(Queue::pushed(RunTriggerChunk::class)->map(fn(RunTriggerChunk $job) => $job->keys)->all())
            ->toBe([[$posts[0]->id, $posts[1]->id], [$posts[2]->id, $posts[3]->id], [$posts[4]->id]])
            ->and(FeedPost::query()->where('status', PostStatus::Published)->count())->toBe(0);
    });

    it('applies the transition in the chunk jobs, recording the trigger', function () {
        $posts = collect(range(1, 3))->map(fn() => scheduledFeedPost('2026-10-14 09:00:00'));

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect($posts->map(fn(FeedPost $post) => $post->fresh()->status)->unique()->all())->toBe([PostStatus::Published])
            ->and($posts[0]->history()->latest('id')->first()->trigger_name)->toBe('publish');
    });

    it('reports a model that throws and skips one whose state changed, carrying on with the rest of its chunk', function () {
        Exceptions::fake();
        $broken = scheduledFeedPost('2026-10-14 09:00:00');
        $changed = scheduledFeedPost('2026-10-14 09:00:00');
        $healthy = scheduledFeedPost('2026-10-14 09:00:00');
        Event::listen(Transitioning::class, fn(Transitioning $event) => $event->model->is($broken) ? throw new RuntimeException('The search index is down.') : null);
        FeedPost::retrieved(function (FeedPost $post) use ($changed) {
            if ($post->is($changed)) {
                DB::table('posts')->where('id', $post->getKey())->update(['status' => PostStatus::Draft->value]);
            }
        });

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn(RuntimeException $exception) => $exception->getMessage() === 'The search index is down.');
        expect($broken->fresh()->status)->toBe(PostStatus::Ready)
            ->and($changed->fresh()->status)->toBe(PostStatus::Draft)
            ->and($healthy->fresh()->status)->toBe(PostStatus::Published);
    });

    it('leaves a model that stopped matching while its chunk job waited', function () {
        Queue::fake([RunTriggerChunk::class]);
        $retracted = scheduledFeedPost('2026-10-14 09:00:00');
        $rescheduled = scheduledFeedPost('2026-10-14 09:00:00');
        $due = scheduledFeedPost('2026-10-14 09:00:00');

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        $retracted->transition('retract');
        $rescheduled->transition('retract')->fill(['publish_at' => '2026-10-15 09:00:00'])->transition('submit');
        Queue::pushed(RunTriggerChunk::class)->each(fn(RunTriggerChunk $job) => app()->call([$job, 'handle']));

        expect($retracted->fresh()->status)->toBe(PostStatus::Draft)
            ->and($rescheduled->fresh()->status)->toBe(PostStatus::Ready)
            ->and($due->fresh()->status)->toBe(PostStatus::Published);
    });

    it('dispatches jobs for at most the configured number of models per run', function () {
        config(['interlock.triggers.max_per_run' => 3]);
        Queue::fake([RunTriggerChunk::class]);
        $posts = collect(range(1, 5))->map(fn() => scheduledFeedPost('2026-10-14 09:00:00'));

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect(Queue::pushed(RunTriggerChunk::class)->map(fn(RunTriggerChunk $job) => $job->keys)->all())
            ->toBe([[$posts[0]->id, $posts[1]->id], [$posts[2]->id]]);
    });

    it('dispatches no more chunk jobs while the last run\'s are still waiting or running', function () {
        Queue::fake([RunTriggerChunk::class]);
        collect(range(1, 3))->each(fn() => scheduledFeedPost('2026-10-14 09:00:00'));

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        [$first, $second] = Queue::pushed(RunTriggerChunk::class)->all();

        $this->travel(1)->minute();
        $this->artisan('interlock:triggers');
        app()->call([$first, 'handle']);
        $this->travel(1)->minute();
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 2);

        app()->call([$second, 'handle']);
        $late = scheduledFeedPost('2026-10-14 09:00:00');
        $this->travel(1)->minute();
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 3);
        Queue::assertPushed(fn(RunTriggerChunk $job) => $job->keys === [$late->id]);
    });

    it('fans out a class-based transition\'s trigger marked #[FanOut]', function () {
        config(['interlock.models' => [Order::class]]);
        Queue::fake([RunTriggerChunk::class]);
        $this->travelTo('2026-10-01 12:00:00');
        $order = Order::create();

        $this->travelTo('2026-10-04 12:00:00');
        $this->artisan('interlock:triggers');

        Queue::assertPushed(fn(RunTriggerChunk $job) => $job->model === Order::class && $job->trigger === 'cancel' && $job->keys === [$order->id]);
    });
});

function scheduledPost(string $publishAt): Post
{
    $post = Post::create(['publish_at' => $publishAt])->transition('submit');

    return $post;
}

function scheduledFeedPost(string $publishAt): FeedPost
{
    return FeedPost::create(['publish_at' => $publishAt])->transition('submit');
}
