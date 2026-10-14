<?php

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Exceptions\UnauthorisedTransitionException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Expense;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\ExpenseStatus;
use RobertBoes\Interlock\Tests\Fixtures\Feeds\FeedPost;
use RobertBoes\Interlock\Tests\Fixtures\TriggerOnlyPosts\TriggerOnlyPost;
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
    expect(scheduledTriggerEvents())->toHaveCount(1)
        ->and(scheduledTriggerEvents()[0]->expression)->toBe('* * * * *');
});

it('runs the triggers on the configured schedule', function () {
    config(['interlock.triggers.schedule.cron' => '*/5 * * * *']);

    expect(scheduledTriggerEvents())->toHaveCount(1)
        ->and(scheduledTriggerEvents()[0]->expression)->toBe('*/5 * * * *');
});

it('runs the triggers every minute when a published config predates the schedule keys', function () {
    config(['interlock.triggers' => ['chunk_size' => 100, 'max_per_run' => 1000]]);

    expect(scheduledTriggerEvents())->toHaveCount(1)
        ->and(scheduledTriggerEvents()[0]->expression)->toBe('* * * * *');
});

it('leaves the triggers off the schedule when the app turns that off', function () {
    config(['interlock.triggers.schedule.enabled' => false]);

    expect(scheduledTriggerEvents())->toBe([]);
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

    it('queues the jobs on the app\'s default connection and queue unless configured', function () {
        Queue::fake();

        $this->artisan('interlock:triggers');

        Queue::assertPushed(fn(RunTrigger $job) => $job->connection === null && $job->queue === null);
    });

    it('queues the jobs on the configured connection and queue', function () {
        config(['interlock.triggers.connection' => 'redis', 'interlock.triggers.queue' => 'triggers']);
        Queue::fake();

        $this->artisan('interlock:triggers');

        Queue::assertPushed(fn(RunTrigger $job) => $job->connection === 'redis' && $job->queue === 'triggers');
    });

    it('reports a trigger whose dispatch fails, releases its lock and dispatches the rest', function () {
        config(['interlock.models' => [Post::class, Expense::class, Order::class], 'queue.default' => 'sync']);
        Exceptions::fake();
        $dispatched = [];
        $queueDown = true;
        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload) use (&$dispatched, &$queueDown) {
            $model = $payload['data']['command']->model;

            if ($queueDown && $model === Expense::class) {
                throw new RuntimeException('The queue is down.');
            }

            $dispatched[] = $model;

            return [];
        });

        try {
            $this->artisan('interlock:triggers')->assertFailed();

            Exceptions::assertReported(fn(RuntimeException $exception) => $exception->getMessage() === 'The queue is down.');
            expect($dispatched)->toBe([Post::class, Order::class]);

            $queueDown = false;
            $this->artisan('interlock:triggers')->assertSuccessful();

            expect($dispatched)->toBe([Post::class, Order::class, Post::class, Expense::class, Order::class]);
        } finally {
            Queue::createPayloadUsing(null);
        }
    });

    it('gives the jobs the configured timeout, failing them once it passes rather than running them again', function () {
        config(['interlock.triggers.timeout' => 45]);
        Queue::fake();

        $this->artisan('interlock:triggers');

        Queue::assertPushed(fn(RunTrigger $job) => $job->timeout === 45 && $job->failOnTimeout === true);
    });

    it('gives the jobs a timeout of a minute when a published config predates the timeout key', function () {
        config(['interlock.triggers' => ['chunk_size' => 100, 'max_per_run' => 1000]]);
        Queue::fake();

        $this->artisan('interlock:triggers');

        Queue::assertPushed(fn(RunTrigger $job) => $job->timeout === 60);
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

    it('carries on after the last chunk a run finished when every run dies at the same point', function () {
        config(['interlock.triggers.chunk_size' => 2]);
        Exceptions::fake();
        $posts = collect(range(1, 5))->map(fn() => scheduledPost('2026-10-14 09:00:00'));
        $broken = $posts->take(2);
        Event::listen(Transitioning::class, fn(Transitioning $event) => $broken->contains(fn(Post $post) => $post->is($event->model)) ? throw new RuntimeException('The search index is down.') : null);
        $this->travelTo('2026-10-14 09:00:00');
        $chunksLoaded = 0;
        DB::listen(function (QueryExecuted $query) use (&$chunksLoaded) {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'limit 2') && ++$chunksLoaded === 2) {
                throw new RuntimeException('The worker was killed.');
            }
        });

        $published = [];
        foreach (range(1, 3) as $run) {
            $chunksLoaded = 0;
            $this->artisan('interlock:triggers');
            $published[$run] = Post::query()->where('status', PostStatus::Published)->count();
        }

        expect($published)->toBe([1 => 0, 2 => 2, 3 => 3]);
    });

    it('stops once three quarters of its timeout has passed, and the next run carries on from there', function () {
        config(['interlock.triggers.chunk_size' => 2, 'interlock.triggers.timeout' => 60]);
        $posts = collect(range(1, 5))->map(fn() => scheduledPost('2026-10-14 09:00:00'));
        $this->travelTo('2026-10-14 09:00:00');
        Event::listen(Transitioned::class, fn() => $this->travel(20)->seconds());

        $this->artisan('interlock:triggers')->assertSuccessful();

        expect($posts->map(fn(Post $post) => $post->fresh()->status)->all())->toBe([
            PostStatus::Published,
            PostStatus::Published,
            PostStatus::Published,
            PostStatus::Ready,
            PostStatus::Ready,
        ]);

        $this->artisan('interlock:triggers');

        expect(Post::query()->where('status', PostStatus::Published)->count())->toBe(5);
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

    it('fails the run, rather than reporting each model, when the database connection is lost', function () {
        Exceptions::fake();
        $first = scheduledPost('2026-10-14 09:00:00');
        $second = scheduledPost('2026-10-14 09:00:00');
        $applied = 0;
        Event::listen(Transitioning::class, function () use (&$applied) {
            $applied++;

            throw lostConnection();
        });

        $this->travelTo('2026-10-14 09:00:00');

        expect(fn() => app()->call([new RunTrigger(Post::class, 'publish'), 'handle']))->toThrow(QueryException::class, 'server has gone away')
            ->and($applied)->toBe(1)
            ->and($first->fresh()->status)->toBe(PostStatus::Ready)
            ->and($second->fresh()->status)->toBe(PostStatus::Ready);
        Exceptions::assertNothingReported();
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

    it('applies a transition whose authorisation allows no one, without an actor', function () {
        config(['interlock.models' => [TriggerOnlyPost::class]]);
        Exceptions::fake();
        $post = TriggerOnlyPost::create(['publish_at' => '2026-10-14 09:00:00'])->transition('submit');

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        Exceptions::assertNothingReported();
        expect($post->fresh()->status)->toBe(PostStatus::Published)
            ->and($post->history()->latest('id')->first())
            ->transition_name->toBe('publish')
            ->trigger_name->toBe('publish')
            ->actor_type->toBeNull()
            ->actor_id->toBeNull();
    });

    it('lets no one apply a transition whose authorisation allows no one by hand, a guest included', function (Closure $apply) {
        $post = TriggerOnlyPost::create(['publish_at' => '2026-10-14 09:00:00'])->transition('submit');

        expect(fn() => $apply($post))->toThrow(UnauthorisedTransitionException::class)
            ->and($post->fresh()->status)->toBe(PostStatus::Ready);
    })->with([
        'a guest' => [fn(TriggerOnlyPost $post) => $post->transition('publish')],
        'on behalf of no one' => [fn(TriggerOnlyPost $post) => $post->transition('publish', null)],
        'a manager' => [fn(TriggerOnlyPost $post) => $post->transition('publish', Employee::manager())],
        'a staged transition on behalf of no one' => [fn(TriggerOnlyPost $post) => $post->stageTransition('publish', null)->save()],
    ]);

    it('offers a transition whose authorisation allows no one to no one, a guest included', function (?Employee $actor) {
        $post = TriggerOnlyPost::create(['publish_at' => '2026-10-14 09:00:00'])->transition('submit');

        expect($post->availableTransitions($actor)->pluck('name')->all())->toBe(['schedule', 'retract'])
            ->and($post->availableTransitions()->pluck('name')->all())->toBe(['schedule', 'retract']);
    })->with([
        'no one' => [null],
        'a manager' => fn() => Employee::manager(),
    ]);

    it('does nothing when its job was queued for a transition that no longer exists', function () {
        Exceptions::fake();
        DB::enableQueryLog();

        app()->call([new RunTrigger(Post::class, 'publish_early'), 'handle']);

        Exceptions::assertNothingReported();
        expect(DB::getQueryLog())->toBe([]);
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

    it('takes an invokable scope class as its condition', function () {
        $due = scheduledFeedPost('2026-10-14 09:00:00');
        $later = scheduledFeedPost('2026-10-14 10:00:00');

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        expect($due->fresh()->status)->toBe(PostStatus::Published)
            ->and($later->fresh()->status)->toBe(PostStatus::Ready);
    });

    it('fails a chunk job, rather than reporting each model, when the database connection is lost', function () {
        Exceptions::fake();
        $posts = collect(range(1, 2))->map(fn() => scheduledFeedPost('2026-10-14 09:00:00'));
        Event::listen(Transitioning::class, fn() => throw lostConnection());
        $this->travelTo('2026-10-14 09:00:00');

        $job = new RunTriggerChunk(FeedPost::class, 'publish', $posts->map->id->all(), 'run', 0);

        expect(fn() => app()->call([$job, 'handle']))->toThrow(QueryException::class, 'server has gone away');
        Exceptions::assertNothingReported();
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

        $this->travel(30)->seconds();
        $this->artisan('interlock:triggers');
        app()->call([$first, 'handle']);
        $this->travel(30)->seconds();
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 2);

        app()->call([$second, 'handle']);
        $late = scheduledFeedPost('2026-10-14 09:00:00');
        $this->travel(30)->seconds();
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 3);
        Queue::assertPushed(fn(RunTriggerChunk $job) => $job->keys === [$late->id]);
    });

    it('dispatches the chunk jobs again once a chunk job that never finished has failed', function () {
        Queue::fake([RunTriggerChunk::class]);
        $posts = collect(range(1, 3))->map(fn() => scheduledFeedPost('2026-10-14 09:00:00'));

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        [$first, $killed] = Queue::pushed(RunTriggerChunk::class)->all();
        app()->call([$first, 'handle']);
        $killed->failed(new RuntimeException('The worker was killed.'));

        $this->travel(1)->minute();
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 3);
        expect(Queue::pushed(RunTriggerChunk::class)->last()->keys)->toBe([$posts[2]->id]);
    });

    it('counts a chunk job that threw and then failed as finished once', function () {
        Queue::fake([RunTriggerChunk::class]);
        collect(range(1, 3))->each(fn() => scheduledFeedPost('2026-10-14 09:00:00'));

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        [$throwing] = Queue::pushed(RunTriggerChunk::class)->all();
        FeedPost::retrieved(fn() => throw new RuntimeException('The database went away.'));
        rescue(fn() => app()->call([$throwing, 'handle']), report: false);
        $throwing->failed(new RuntimeException('The database went away.'));

        $this->travel(1)->minute();
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 2);
    });

    it('carries on with the chunks it did not dispatch when dispatching a chunk job fails', function () {
        Exceptions::fake();
        $posts = collect(range(1, 5))->map(fn() => scheduledFeedPost('2026-10-14 09:00:00'));
        $queueDown = true;
        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload) use ($posts, &$queueDown) {
            if ($queueDown && $payload['data']['command'] instanceof RunTriggerChunk && in_array($posts[2]->id, $payload['data']['command']->keys)) {
                throw new RuntimeException('The queue is down.');
            }

            return [];
        });

        try {
            $this->travelTo('2026-10-14 09:00:00');
            $this->artisan('interlock:triggers');

            expect(FeedPost::query()->where('status', PostStatus::Published)->count())->toBe(2);

            $queueDown = false;
            $this->travel(1)->minute();
            $this->artisan('interlock:triggers');

            expect(FeedPost::query()->where('status', PostStatus::Published)->count())->toBe(5);
        } finally {
            Queue::createPayloadUsing(null);
        }
    });

    it('does not count a chunk job of an earlier run that finishes late towards the current run', function () {
        config(['interlock.triggers.chunk_size' => 1]);
        Queue::fake([RunTriggerChunk::class]);
        collect(range(1, 2))->each(fn() => scheduledFeedPost('2026-10-14 09:00:00'));

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        [$late] = Queue::pushed(RunTriggerChunk::class)->all();

        $this->travel(1)->day();
        $this->artisan('interlock:triggers');
        [, , $current] = Queue::pushed(RunTriggerChunk::class)->all();
        app()->call([$late, 'handle']);
        app()->call([$current, 'handle']);
        $this->travel(1)->minute();
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 4);
    });

    it('stops waiting for chunk jobs that never finish once each could have run out its timeout in turn', function () {
        config(['interlock.triggers.timeout' => 200]);
        Queue::fake([RunTriggerChunk::class]);
        collect(range(1, 3))->each(fn() => scheduledFeedPost('2026-10-14 09:00:00'));

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        $this->travelTo('2026-10-14 09:06:39');
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 2);

        $this->travelTo('2026-10-14 09:06:40');
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 4);
    });

    it('waits at least five minutes for chunk jobs that never finish, however short their timeouts', function () {
        config(['interlock.triggers.timeout' => 60]);
        Queue::fake([RunTriggerChunk::class]);
        collect(range(1, 3))->each(fn() => scheduledFeedPost('2026-10-14 09:00:00'));

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');
        $this->travelTo('2026-10-14 09:04:59');
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 2);

        $this->travelTo('2026-10-14 09:05:00');
        $this->artisan('interlock:triggers');

        Queue::assertPushed(RunTriggerChunk::class, 4);
    });

    it('gives the chunk jobs the configured timeout, failing them once it passes rather than running them again', function () {
        config(['interlock.triggers.timeout' => 45]);
        Queue::fake([RunTriggerChunk::class]);
        scheduledFeedPost('2026-10-14 09:00:00');

        $this->travelTo('2026-10-14 09:00:00');
        $this->artisan('interlock:triggers');

        Queue::assertPushed(fn(RunTriggerChunk $job) => $job->timeout === 45 && $job->failOnTimeout === true);
    });

    it('stops a chunk job once three quarters of its timeout has passed, leaving the rest for a later run', function () {
        config(['interlock.triggers.timeout' => 60]);
        $posts = collect(range(1, 3))->map(fn() => scheduledFeedPost('2026-10-14 09:00:00'));
        $this->travelTo('2026-10-14 09:00:00');
        Event::listen(Transitioned::class, fn() => $this->travel(30)->seconds());

        app()->call([new RunTriggerChunk(FeedPost::class, 'publish', $posts->map->id->all(), 'run', 0), 'handle']);

        expect($posts->map(fn(FeedPost $post) => $post->fresh()->status)->all())->toBe([
            PostStatus::Published,
            PostStatus::Published,
            PostStatus::Ready,
        ]);
    });

    it('does nothing when a chunk job was queued for a transition that no longer exists', function () {
        $post = scheduledFeedPost('2026-10-14 09:00:00');
        $this->travelTo('2026-10-14 09:00:00');

        app()->call([new RunTriggerChunk(FeedPost::class, 'publish_early', [$post->id], 'run', 0), 'handle']);

        expect($post->fresh()->status)->toBe(PostStatus::Ready);
    });

    it('queues the chunk jobs on the configured connection and queue', function () {
        config(['interlock.triggers.connection' => 'redis', 'interlock.triggers.queue' => 'triggers']);
        Queue::fake([RunTriggerChunk::class]);
        scheduledFeedPost('2026-10-14 09:00:00');

        $this->travelTo('2026-10-14 09:00:00');
        app()->call([new RunTrigger(FeedPost::class, 'publish'), 'handle']);

        Queue::assertPushed(RunTriggerChunk::class, 1);
        Queue::assertPushed(fn(RunTriggerChunk $job) => $job->connection === 'redis' && $job->queue === 'triggers');
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

function lostConnection(): QueryException
{
    return new QueryException('testing', 'update "posts" set "status" = ?', ['published'], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
}

/**
 * @return list<ScheduledEvent>
 */
function scheduledTriggerEvents(): array
{
    return array_values(array_filter(
        app(Schedule::class)->events(),
        fn(ScheduledEvent $event) => str_contains((string) $event->command, 'interlock:triggers'),
    ));
}
