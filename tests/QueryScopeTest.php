<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RobertBoes\Interlock\Exceptions\InvalidStateQueryException;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Scopes\InFromStateOf;
use RobertBoes\Interlock\Scopes\InState;
use RobertBoes\Interlock\Scopes\NotInState;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Discovery\Comment;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\ScopedOrder;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Shipment;

beforeEach(fn() => Interlock::bypassForTest('Create models in the states being queried'));

it('finds the models in a state', function () {
    $ready = Post::create(['status' => PostStatus::Ready]);
    Post::create(['status' => PostStatus::Draft]);
    Post::create(['status' => PostStatus::Published]);

    expect(Post::query()->tap(new InState(PostStatus::Ready))->pluck('id')->all())->toBe([$ready->getKey()]);
});

it('finds the models in any child of a parent state', function () {
    $packing = Order::create(['status' => OrderStatus::Packing]);
    $labelCreated = Order::create(['status' => OrderStatus::LabelCreated]);
    Order::create(['status' => OrderStatus::Pending]);
    Order::create(['status' => OrderStatus::Shipped]);

    expect(Order::query()->tap(new InState(OrderStatus::Preparing))->pluck('id')->all())
        ->toEqualCanonicalizing([$packing->getKey(), $labelCreated->getKey()]);
});

it('finds the models in any of several states', function () {
    $ready = Post::create(['status' => PostStatus::Ready]);
    $scheduled = Post::create(['status' => PostStatus::Scheduled]);
    Post::create(['status' => PostStatus::Draft]);

    expect(Post::query()->tap(new InState([PostStatus::Ready, PostStatus::Scheduled]))->pluck('id')->all())
        ->toEqualCanonicalizing([$ready->getKey(), $scheduled->getKey()]);
});

it('finds the models not in a state', function () {
    $draft = Post::create(['status' => PostStatus::Draft]);
    $published = Post::create(['status' => PostStatus::Published]);
    Post::create(['status' => PostStatus::Ready]);

    expect(Post::query()->tap(new NotInState(PostStatus::Ready))->pluck('id')->all())
        ->toEqualCanonicalizing([$draft->getKey(), $published->getKey()]);
});

it('leaves out the models in any child of a parent state', function () {
    $pending = Order::create(['status' => OrderStatus::Pending]);
    $shipped = Order::create(['status' => OrderStatus::Shipped]);
    Order::create(['status' => OrderStatus::Packing]);
    Order::create(['status' => OrderStatus::LabelCreated]);

    expect(Order::query()->tap(new NotInState(OrderStatus::Preparing))->pluck('id')->all())
        ->toEqualCanonicalizing([$pending->getKey(), $shipped->getKey()]);
});

it('leaves out the models in any of several states', function () {
    $draft = Post::create(['status' => PostStatus::Draft]);
    Post::create(['status' => PostStatus::Ready]);
    Post::create(['status' => PostStatus::Published]);

    expect(Post::query()->tap(new NotInState([PostStatus::Ready, PostStatus::Published]))->pluck('id')->all())
        ->toBe([$draft->getKey()]);
});

it('queries the given state attribute', function () {
    $ready = Post::create(['status' => PostStatus::Ready]);
    $draft = Post::create(['status' => PostStatus::Draft]);

    expect(Post::query()->tap(new InState(PostStatus::Ready, stateAttribute: 'status'))->pluck('id')->all())
        ->toBe([$ready->getKey()])
        ->and(Post::query()->tap(new NotInState(PostStatus::Ready, stateAttribute: 'status'))->pluck('id')->all())
        ->toBe([$draft->getKey()]);
});

it('throws when the model has no state machine with states of the enum', function () {
    expect(fn() => Post::query()->tap(new InState(OrderStatus::Pending))->get())->toThrow(
        InvalidStateQueryException::class,
        Post::class . ' has no state machine with states of ' . OrderStatus::class . '. Its state attributes are [status].',
    );
});

it('throws when the model has no state machine on the given attribute', function () {
    expect(fn() => Post::query()->tap(new InState(PostStatus::Ready, stateAttribute: 'title'))->get())->toThrow(
        InvalidStateQueryException::class,
        Post::class . ' has no state machine on [title]. Its state attributes are [status].',
    );
});

it('throws when a state is not one the state attribute can hold', function () {
    expect(fn() => Order::query()->tap(new InState([OrderStatus::Pending, PostStatus::Ready]))->get())->toThrow(
        InvalidStateQueryException::class,
        'The [status] of ' . Order::class . ' holds states of ' . OrderStatus::class . ', not [ready] of ' . PostStatus::class . '.',
    );
});

it('throws when no states are given', function () {
    expect(fn() => new InState([]))->toThrow(
        InvalidStateQueryException::class,
        'Pass at least one state to query by.',
    );
});

it('throws when a model without Interlock is queried by state', function () {
    expect(fn() => Comment::query()->tap(new InState(PostStatus::Ready))->get())->toThrow(
        InvalidStateQueryException::class,
        Comment::class . ' has no state machines. Add #[UseStateMachine] and the HasStateMachines trait to query it by state.',
    );
});

it('finds the models in a from-state of a transition', function () {
    $ready = Post::create(['status' => PostStatus::Ready]);
    $scheduled = Post::create(['status' => PostStatus::Scheduled]);
    Post::create(['status' => PostStatus::Draft]);
    Post::create(['status' => PostStatus::Published]);

    expect(Post::query()->tap(new InFromStateOf('publish'))->pluck('id')->all())
        ->toEqualCanonicalizing([$ready->getKey(), $scheduled->getKey()]);
});

it('finds the models in any child of a parent from-state of a transition', function () {
    $pending = Order::create(['status' => OrderStatus::Pending]);
    $packing = Order::create(['status' => OrderStatus::Packing]);
    $labelCreated = Order::create(['status' => OrderStatus::LabelCreated]);
    Order::create(['status' => OrderStatus::Shipped]);
    Order::create(['status' => OrderStatus::Cancelled]);

    expect(Order::query()->tap(new InFromStateOf('cancel'))->pluck('id')->all())
        ->toEqualCanonicalizing([$pending->getKey(), $packing->getKey(), $labelCreated->getKey()]);
});

it('queries the model\'s own state attribute when another table in the query has one too', function () {
    $packing = Order::create(['status' => OrderStatus::Packing]);
    $shipped = Order::create(['status' => OrderStatus::Shipped]);
    Post::create(['id' => $packing->getKey(), 'status' => PostStatus::Draft]);
    Post::create(['id' => $shipped->getKey(), 'status' => PostStatus::Draft]);

    $joined = fn() => Order::query()->join('posts', 'posts.id', '=', 'orders.id');

    expect($joined()->tap(new InState(OrderStatus::Preparing))->pluck('orders.id')->all())->toBe([$packing->getKey()])
        ->and($joined()->tap(new NotInState(OrderStatus::Preparing))->pluck('orders.id')->all())->toBe([$shipped->getKey()])
        ->and($joined()->tap(new InFromStateOf('cancel'))->pluck('orders.id')->all())->toBe([$packing->getKey()]);
});

it('constrains an eager load', function (callable $scope, OrderStatus $kept) {
    $packing = Order::create(['status' => OrderStatus::Packing]);
    $shipped = Order::create(['status' => OrderStatus::Shipped]);
    $toPacking = Shipment::create(['order_id' => $packing->getKey(), 'tracking_code' => 'PACKING']);
    $toShipped = Shipment::create(['order_id' => $shipped->getKey(), 'tracking_code' => 'SHIPPED']);

    $orderStatuses = fn($shipments) => $shipments->mapWithKeys(fn(Shipment $shipment) => [
        $shipment->tracking_code => $shipment->order?->status,
    ])->all();

    $expected = [
        'PACKING' => $kept === OrderStatus::Packing ? OrderStatus::Packing : null,
        'SHIPPED' => $kept === OrderStatus::Shipped ? OrderStatus::Shipped : null,
    ];

    expect($orderStatuses(Shipment::with(['order' => $scope()])->orderBy('id')->get()))->toBe($expected)
        ->and($orderStatuses(Shipment::query()->orderBy('id')->get()->load(['order' => $scope()])))->toBe($expected)
        ->and($toPacking->load(['order' => $scope()])->order?->getKey())->toBe($kept === OrderStatus::Packing ? $packing->getKey() : null)
        ->and($toShipped->load(['order' => $scope()])->order?->getKey())->toBe($kept === OrderStatus::Shipped ? $shipped->getKey() : null);
})->with([
    'in state' => [fn() => new InState(OrderStatus::Preparing), OrderStatus::Packing],
    'not in state' => [fn() => new NotInState(OrderStatus::Preparing), OrderStatus::Shipped],
    'in from-state of' => [fn() => new InFromStateOf('cancel'), OrderStatus::Packing],
]);

describe('scope methods', function () {
    it('finds the models in a state', function () {
        $packing = ScopedOrder::create(['status' => OrderStatus::Packing]);
        $labelCreated = ScopedOrder::create(['status' => OrderStatus::LabelCreated]);
        ScopedOrder::create(['status' => OrderStatus::Pending]);

        expect(ScopedOrder::whereInState(OrderStatus::Preparing)->pluck('id')->all())
            ->toEqualCanonicalizing([$packing->getKey(), $labelCreated->getKey()])
            ->and(ScopedOrder::query()->whereInState([OrderStatus::Pending, OrderStatus::Packing], 'status')->count())
            ->toBe(2);
    });

    it('finds the models not in a state', function () {
        $pending = ScopedOrder::create(['status' => OrderStatus::Pending]);
        ScopedOrder::create(['status' => OrderStatus::Packing]);
        ScopedOrder::create(['status' => OrderStatus::LabelCreated]);

        expect(ScopedOrder::whereNotInState(OrderStatus::Preparing)->pluck('id')->all())
            ->toBe([$pending->getKey()])
            ->and(ScopedOrder::query()->whereNotInState([OrderStatus::Pending, OrderStatus::Packing], 'status')->count())
            ->toBe(1);
    });

    it('finds the models in a from-state of a transition', function () {
        $pending = ScopedOrder::create(['status' => OrderStatus::Pending]);
        $labelCreated = ScopedOrder::create(['status' => OrderStatus::LabelCreated]);
        ScopedOrder::create(['status' => OrderStatus::Shipped]);

        expect(ScopedOrder::whereInFromStateOf('cancel')->pluck('id')->all())
            ->toEqualCanonicalizing([$pending->getKey(), $labelCreated->getKey()]);
    });

    it('is not added to models that do not opt in', function (string $model, string $scope) {
        expect((new $model())->newQuery()->hasNamedScope($scope))->toBeFalse()
            ->and(Builder::hasGlobalMacro($scope))->toBeFalse()
            ->and(QueryBuilder::hasMacro($scope))->toBeFalse();
    })->with([
        'an interlocked model' => Order::class,
        'a model without Interlock' => Comment::class,
    ])->with(['whereInState', 'whereNotInState', 'whereInStateFor', 'whereInFromStateOf']);
});

it('throws for a transition the model does not have', function () {
    expect(fn() => Post::query()->tap(new InFromStateOf('archive'))->get())->toThrow(
        UnknownTransitionException::class,
        Post::class . ' has no transition named [archive].',
    );
});
