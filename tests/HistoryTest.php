<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobertBoes\Interlock\Exceptions\MissingHistoryTableException;
use RobertBoes\Interlock\History\DatabaseHistoryRecorder;
use RobertBoes\Interlock\History\HistoryRecord;
use RobertBoes\Interlock\History\HistoryRecorder;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\InterlockServiceProvider;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Contracts\Contract;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\History\InMemoryHistoryRecorder;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Ship;

it('records a transition on a machine with history', function () {
    $order = Order::create();

    $order->transition('prepare', Employee::manager(id: 7));

    expect($order->history)->toHaveCount(1)
        ->and($order->history->first()->only([
            'model_type', 'model_id', 'state_attribute', 'transition_name', 'from_state', 'target_state',
            'actor_type', 'actor_id', 'old_attributes', 'new_attributes', 'bypass_reason', 'trigger_name',
        ]))->toBe([
            'model_type' => Order::class,
            'model_id' => $order->getKey(),
            'state_attribute' => 'status',
            'transition_name' => 'prepare',
            'from_state' => 'pending',
            'target_state' => 'packing',
            'actor_type' => Employee::class,
            'actor_id' => 7,
            'old_attributes' => ['status' => 'pending'],
            'new_attributes' => ['status' => 'packing'],
            'bypass_reason' => null,
            'trigger_name' => null,
        ])
        ->and($order->history->first()->created_at)->not->toBeNull();
});

it('records the attributes the apply step set, and not the model\'s other unsaved changes', function () {
    $order = orderReadyToShip();
    $order->delivered_at = '2026-10-14 12:00:00';

    $order->transition(new Ship('TRACK-1'));

    expect($order->history()->latest('id')->first())
        ->old_attributes->toBe(['status' => 'label_created', 'tracking_code' => null])
        ->new_attributes->toBe(['status' => 'shipped', 'tracking_code' => 'TRACK-1']);
    expect($order->fresh()->delivered_at)->not->toBeNull();
});

it('records nothing for a machine without history', function () {
    $order = Order::create();

    $order->transition('authorize_payment');

    expect($order->history)->toBeEmpty();
});

it('leaves no entry when the transition fails', function () {
    $post = Post::create()->transition('submit');
    $first = Post::find($post->getKey());
    $second = Post::find($post->getKey());
    $first->transition('publish');

    rescue(fn() => $second->transition('retract'), report: false);

    expect($post->history()->oldest('id')->pluck('transition_name')->all())->toBe(['submit', 'publish']);
});

it('records history through the recorder the config names', function () {
    config(['interlock.history.recorder' => InMemoryHistoryRecorder::class]);
    $order = Order::create();

    $order->transition('prepare');

    expect(app(HistoryRecorder::class))->toBeInstanceOf(InMemoryHistoryRecorder::class)
        ->records->toHaveCount(1)
        ->and($order->history()->count())->toBe(0);
});

it('records history in the database when a published config predates the recorder key', function () {
    config(['interlock' => Arr::except(config('interlock'), 'history')]);

    expect(app(HistoryRecorder::class))->toBeInstanceOf(DatabaseHistoryRecorder::class);
});

it('records history in the transition\'s transaction, so a failing recorder writes nothing', function () {
    $order = Order::create();
    app()->instance(HistoryRecorder::class, new class implements HistoryRecorder {
        public function record(HistoryRecord $record): void
        {
            throw new RuntimeException('History store unavailable');
        }
    });

    expect(fn() => $order->transition('prepare'))->toThrow(RuntimeException::class, 'History store unavailable');

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('leaves the model as it was loaded when a failing recorder rolls the transition back', function () {
    $order = Order::create()->transition('prepare');
    app()->instance(HistoryRecorder::class, new class implements HistoryRecorder {
        public function record(HistoryRecord $record): void
        {
            throw new RuntimeException('History store unavailable');
        }
    });
    $order->delivered_at = now();

    rescue(fn() => $order->transition('create_label'), report: false);

    expect($order)
        ->status->toBe(OrderStatus::Packing)
        ->getRawOriginal('status')->toBe('packing')
        ->getRawOriginal('delivered_at')->toBeNull()
        ->getDirty()->toHaveKeys(['delivered_at'])->not->toHaveKey('status')
        ->wasChanged('status')->toBeTrue()
        ->and($order->getChanges())->toHaveKey('status');

    app()->forgetInstance(HistoryRecorder::class);
    $order->transition('create_label');

    expect($order->fresh())
        ->status->toBe(OrderStatus::LabelCreated)
        ->delivered_at->not->toBeNull();
});

it('records a bypassed write with its reason and no transition name', function () {
    $post = Post::create();

    Interlock::bypass('Fix posts stuck in draft', fn() => $post->update(['status' => PostStatus::Published]));

    expect($post->history->sole()->only([
        'state_attribute', 'transition_name', 'from_state', 'target_state', 'old_attributes', 'new_attributes', 'bypass_reason',
    ]))->toBe([
        'state_attribute' => 'status',
        'transition_name' => null,
        'from_state' => 'draft',
        'target_state' => 'published',
        'old_attributes' => ['status' => 'draft'],
        'new_attributes' => ['status' => 'published'],
        'bypass_reason' => 'Fix posts stuck in draft',
    ]);
});

it('records a model created in its state inside a bypass, without a from-state', function () {
    $post = Interlock::bypass('Import published posts', fn() => Post::create(['status' => PostStatus::Published]));

    expect($post->history->sole())
        ->from_state->toBeNull()
        ->target_state->toBe('published')
        ->bypass_reason->toBe('Import published posts');
});

it('records a model a factory created in its state, with the reason "factory"', function () {
    $post = Post::factory()->create(['status' => PostStatus::Published]);

    expect($post->history->sole())
        ->target_state->toBe('published')
        ->bypass_reason->toBe('factory');
});

it('records no entry for creating a model in its initial state', function () {
    expect(Post::create()->history)->toBeEmpty();
});

it('records a transition when model events are muted', function () {
    $post = Post::create();

    Post::withoutEvents(fn() => $post->transition('submit'));

    expect($post->history->sole()->transition_name)->toBe('submit');
});

it('records a bypassed write when model events are muted', function (Closure $write) {
    $post = Post::create();

    Interlock::bypass('Fix posts stuck in draft', fn() => $write($post));

    expect($post->history->sole())
        ->target_state->toBe('published')
        ->bypass_reason->toBe('Fix posts stuck in draft');
})->with([
    'saveQuietly()' => fn(Post $post) => $post->fill(['status' => PostStatus::Published])->saveQuietly(),
    'updateQuietly()' => fn(Post $post) => $post->updateQuietly(['status' => PostStatus::Published]),
    'save() inside Model::withoutEvents()' => fn(Post $post) => Post::withoutEvents(
        fn() => $post->fill(['status' => PostStatus::Published])->save(),
    ),
]);

it('records a model created quietly in its state inside a bypass', function () {
    $post = Interlock::bypass('Import published posts', fn() => Post::createQuietly(['status' => PostStatus::Published]));

    expect($post->history->sole()->bypass_reason)->toBe('Import published posts');
});

it('records the authenticated user as the actor, unless one is given', function () {
    $this->actingAs(Employee::manager(id: 7));
    $post = Post::create();

    $post->transition('submit');
    $post->transition('publish', null);

    expect($post->history()->oldest('id')->get(['actor_type', 'actor_id'])->toArray())->toBe([
        ['actor_type' => Employee::class, 'actor_id' => 7],
        ['actor_type' => null, 'actor_id' => null],
    ]);
});

it('records the authenticated user as the actor of a bypassed write', function () {
    $this->actingAs(Employee::manager(id: 7));
    $post = Post::create();

    Interlock::bypass('Fix posts stuck in draft', fn() => $post->update(['status' => PostStatus::Published]));

    expect($post->history->sole())
        ->actor_type->toBe(Employee::class)
        ->actor_id->toBe(7);
});

it('records each state attribute of a model on its own, and filters the history by state attribute', function () {
    $contract = Contract::create();

    $contract->transition('approve_legally');
    $contract->transition('reject_budget');
    $contract->transition('reopen_budget');

    expect($contract->history)->toHaveCount(3)
        ->and($contract->history('legal_status')->pluck('transition_name')->all())->toBe(['approve_legally'])
        ->and($contract->history('finance_status')->oldest('id')->pluck('transition_name')->all())->toBe(['reject_budget', 'reopen_budget']);
});

it('records each staged transition of one save as its own entry, with its own actor', function () {
    $contract = Contract::create();

    $contract->stageTransition('approve_legally', Employee::manager(id: 7))
        ->stageTransition('reject_budget', Employee::manager(id: 8))
        ->save();

    expect($contract->history()->oldest('id')->get()->map->only(['state_attribute', 'transition_name', 'actor_id', 'old_attributes', 'new_attributes'])->all())->toBe([
        ['state_attribute' => 'legal_status', 'transition_name' => 'approve_legally', 'actor_id' => 7, 'old_attributes' => ['legal_status' => 'pending'], 'new_attributes' => ['legal_status' => 'approved']],
        ['state_attribute' => 'finance_status', 'transition_name' => 'reject_budget', 'actor_id' => 8, 'old_attributes' => ['finance_status' => 'pending'], 'new_attributes' => ['finance_status' => 'rejected']],
    ]);
});

it('records history through a recorder bound in the container', function () {
    $recorder = new class implements HistoryRecorder {
        /** @var list<HistoryRecord> */
        public array $records = [];

        public function record(HistoryRecord $record): void
        {
            $this->records[] = $record;
        }
    };
    app()->instance(HistoryRecorder::class, $recorder);
    $order = orderReadyToShip();

    $order->transition(new Ship('TRACK-1'));

    expect($recorder->records)->toHaveCount(3)
        ->and($recorder->records[2])
        ->model->toBe($order)
        ->stateAttribute->toBe('status')
        ->transition->toBe('ship')
        ->fromState->toBe(OrderStatus::LabelCreated)
        ->targetState->toBe(OrderStatus::Shipped)
        ->oldAttributes->toBe(['status' => 'label_created', 'tracking_code' => null])
        ->newAttributes->toBe(['status' => 'shipped', 'tracking_code' => 'TRACK-1'])
        ->and($order->history)->toBeEmpty();
});

it('publishes the history migration under the interlock-migrations tag', function () {
    $published = InterlockServiceProvider::pathsToPublish(InterlockServiceProvider::class, 'interlock-migrations');

    expect($published)->toHaveCount(1)
        ->and(array_key_first($published))->toEndWith('database/migrations/create_interlock_history_table.php.stub')
        ->and(array_values($published)[0])
        ->toStartWith(database_path('migrations'))
        ->toMatch('#/\d{4}_\d{2}_\d{2}_\d{6}_create_interlock_history_table\.php$#');
});

it('writes nothing when a bypassed write cannot be recorded', function () {
    $post = Post::create();
    app()->instance(HistoryRecorder::class, new class implements HistoryRecorder {
        public function record(HistoryRecord $record): void
        {
            throw new RuntimeException('History store unavailable');
        }
    });

    rescue(fn() => Interlock::bypass('Fix posts stuck in draft', fn() => $post->update(['status' => PostStatus::Published])), report: false);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

it('writes nothing when an updating listener makes a bypassed write that cannot be recorded', function () {
    $post = Post::create();
    Post::updating(fn(Post $post) => $post->status = PostStatus::Published);
    app()->instance(HistoryRecorder::class, new class implements HistoryRecorder {
        public function record(HistoryRecord $record): void
        {
            throw new RuntimeException('History store unavailable');
        }
    });

    expect(fn() => Interlock::bypass('Publish on every edit', fn() => $post->update(['title' => 'Edited title'])))
        ->toThrow(RuntimeException::class, 'History store unavailable');

    expect($post->fresh())
        ->status->toBe(PostStatus::Draft)
        ->title->toBeNull();
});

it('writes nothing when a creating listener makes a bypassed write that cannot be recorded', function () {
    Post::creating(fn(Post $post) => $post->status = PostStatus::Published);
    app()->instance(HistoryRecorder::class, new class implements HistoryRecorder {
        public function record(HistoryRecord $record): void
        {
            throw new RuntimeException('History store unavailable');
        }
    });

    expect(fn() => Interlock::bypass('Import published posts', fn() => Post::create(['title' => 'Imported'])))
        ->toThrow(RuntimeException::class, 'History store unavailable');

    expect(Post::query()->exists())->toBeFalse();
});

it('says how to fix a missing history table, naming the machine that records history', function () {
    $order = Order::create();
    Schema::drop('interlock_history');

    $thrown = thrownBy(fn() => $order->transition('prepare'));

    expect($thrown)->toBeInstanceOf(MissingHistoryTableException::class)
        ->and($thrown->getMessage())->toBe(sprintf(
            '%s records history, but the interlock_history table does not exist on database connection [%s]. Publish and run the history migration with `php artisan vendor:publish --tag=interlock-migrations` and `php artisan migrate`, or remove #[RecordsHistory] from %s.',
            OrderStatusMachine::class,
            DB::getDefaultConnection(),
            OrderStatusMachine::class,
        ))
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending);
})->skip(fn() => DB::connection()->getDriverName() === 'mysql', 'MySQL commits the test\'s transaction on dropping a table.');

it('checks for the history table once per connection, before its first entry', function () {
    [$first, $second] = [Order::create(), Order::create()];
    $tableChecks = 0;
    DB::listen(function (QueryExecuted $query) use (&$tableChecks) {
        if (str_contains($query->sql, 'interlock_history') && ! str_starts_with($query->sql, 'insert')) {
            $tableChecks++;
        }
    });

    $first->transition('prepare')->transition('create_label');
    $second->transition('prepare');

    expect($tableChecks)->toBe(1);
});
