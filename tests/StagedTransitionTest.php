<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\FailedGuardException;
use RobertBoes\Interlock\Exceptions\IllegalTransitionException;
use RobertBoes\Interlock\Exceptions\NotAFromStateException;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Exceptions\UnauthorizedTransitionException;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Expense;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\ExpenseStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderShipped;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentCaptured;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Ship;

/**
 * @return Collection<int, array{query: string}>
 */
function updatesDuring(Closure $callback): Collection
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    $callback();

    return collect(DB::getQueryLog())->filter(fn(array $query) => str_starts_with(strtolower($query['query']), 'update'));
}

it('throws straight away when staging a transition whose from-states do not include the current state', function () {
    $order = Order::create();

    expect(thrownBy(fn() => $order->stageTransition('ship')))
        ->toBeInstanceOf(NotAFromStateException::class)
        ->model->toBe($order)
        ->transition->toBe('ship')
        ->stateAttribute->toBe('status')
        ->currentState->toBe(OrderStatus::Pending)
        ->fromStates->toBe([OrderStatus::LabelCreated]);

    expect($order->status)->toBe(OrderStatus::Pending);
});

it('reads the target state from the model once a transition is staged, and writes nothing yet', function () {
    $order = Order::create();

    $result = $order->stageTransition('prepare');

    expect($result)->toBe($order)
        ->and($order->status)->toBe(OrderStatus::Packing)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('writes transitions staged on several machines in one update on save', function () {
    $order = orderReadyToShip();
    Event::fake([Transitioned::class, OrderShipped::class]);

    $order->stageTransition(new Ship('TRACK-1'))->stageTransition('authorize_payment');
    $updates = updatesDuring(fn() => $order->save());

    expect($updates)->toHaveCount(1)
        ->and($order->fresh())
        ->status->toBe(OrderStatus::Shipped)
        ->payment_status->toBe(PaymentStatus::Authorized)
        ->tracking_code->toBe('TRACK-1')
        ->and($order->shipments()->pluck('tracking_code')->all())->toBe(['TRACK-1']);
    Event::assertDispatched(OrderShipped::class);
    Event::assertDispatched(fn(Transitioned $event) => $event->transition === 'ship' && $event->targetState === OrderStatus::Shipped);
    Event::assertDispatched(fn(Transitioned $event) => $event->transition === 'authorize_payment' && $event->targetState === PaymentStatus::Authorized);
});

it('writes the model\'s other unsaved changes in the same update as a staged transition', function () {
    $post = Post::create();

    $post->stageTransition('submit');
    $post->title = 'Interlocking for beginners';
    $updates = updatesDuring(fn() => $post->save());

    expect($updates)->toHaveCount(1)
        ->and($post->fresh())
        ->status->toBe(PostStatus::Ready)
        ->title->toBe('Interlocking for beginners');
});

it('runs the apply step of a staged transition on save, not when staging', function () {
    $order = orderReadyToShip();

    $order->stageTransition(new Ship('TRACK-1'));

    expect($order->shipments()->count())->toBe(0)
        ->and($order->tracking_code)->toBeNull();

    $order->save();

    expect($order->shipments()->count())->toBe(1)
        ->and($order->fresh()->tracking_code)->toBe('TRACK-1');
});

it('dispatches Transitioning on save and Transitioned only after the save commits', function () {
    $order = Order::create();
    Event::fake([Transitioning::class, Transitioned::class]);

    $order->stageTransition('prepare');
    Event::assertNotDispatched(Transitioning::class);

    DB::transaction(function () use ($order) {
        $order->save();

        Event::assertDispatched(Transitioning::class);
        Event::assertNotDispatched(Transitioned::class);
    });

    Event::assertDispatched(fn(Transitioned $event) => $event->transition === 'prepare' && $event->fromState === OrderStatus::Pending);
});

it('checks the guards of a staged transition on save, against the data as it will be stored', function () {
    $expense = Expense::create();

    $expense->stageTransition('submit');
    $expense->amount = 4200;
    $expense->save();

    expect($expense->fresh())
        ->status->toBe(ExpenseStatus::Submitted)
        ->amount->toBe(4200);
});

it('authorizes the actor of a staged transition on save', function () {
    $expense = Expense::create(['amount' => 4200, 'rejection_reason' => 'No receipt'])->transition('submit');

    $expense->stageTransition('reject', Employee::staff());

    expect(fn() => $expense->save())->toThrow(UnauthorizedTransitionException::class);
});

it('authorizes a staged transition for the authenticated user when no actor is given', function () {
    $expense = Expense::create(['amount' => 4200, 'rejection_reason' => 'No receipt'])->transition('submit');
    $this->actingAs(Employee::manager());

    $expense->stageTransition('reject')->save();

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Rejected);
});

it('compares and swaps every staged state attribute, so one stale machine fails the whole save', function () {
    $order = Order::create();
    $stale = Order::find($order->getKey());
    $order->transition('authorize_payment');

    $stale->stageTransition('prepare')->stageTransition('authorize_payment');

    expect(thrownBy(fn() => $stale->save()))
        ->toBeInstanceOf(StaleStateException::class)
        ->context()->toBe([
            'model' => Order::class,
            'key' => $order->getKey(),
            'transition' => 'authorize_payment',
            'state_attribute' => 'payment_status',
            'loaded_state' => 'unpaid',
            'stored_state' => 'authorized',
            'row_deleted' => false,
        ]);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->payment_status->toBe(PaymentStatus::Authorized);
});

it('throws when a second transition is staged on the same machine', function () {
    $order = Order::create()->stageTransition('prepare');

    expect(fn() => $order->stageTransition('cancel'))->toThrow(
        IllegalTransitionException::class,
        'Cannot stage transition [cancel] on ' . Order::class . ' [' . $order->getKey() . ']: transition [prepare] is already staged for its [status], and a machine holds one staged transition at a time. Save the model, or discard its staged transitions, first.',
    );

    $order->save();

    expect($order->fresh()->status)->toBe(OrderStatus::Packing);
});

it('throws on save when a state attribute with a staged transition was written directly', function () {
    $order = Order::create()->stageTransition('prepare');
    $order->status = OrderStatus::Cancelled;

    expect(fn() => $order->save())->toThrow(
        DirectWriteException::class,
        'Cannot save ' . Order::class . ' [' . $order->getKey() . ']: its [status] was changed to [cancelled] after transition [prepare] was staged to move it to [packing]. Discard the staged transition instead.',
    );

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('lets a guarded attribute be written only by a staged transition allowed to write it', function () {
    $order = Order::create()->stageTransition('prepare')->stageTransition('authorize_payment');
    $order->tracking_code = 'TRACK-1';

    expect(fn() => $order->save())->toThrow(
        DirectWriteException::class,
        'Cannot save ' . Order::class . ' [' . $order->getKey() . ']: its guarded attribute [tracking_code] was changed during transitions [prepare, authorize_payment], none of which may write it. Only [ship] may write it.',
    );

    expect($order->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->tracking_code->toBeNull();
});

it('discards staged transitions, putting each state attribute back', function () {
    $order = Order::create()->stageTransition('prepare')->stageTransition('authorize_payment');
    Event::fake([Transitioned::class]);

    $result = $order->discardStagedTransitions();
    $order->save();

    expect($result)->toBe($order)
        ->and($order)
        ->status->toBe(OrderStatus::Pending)
        ->payment_status->toBe(PaymentStatus::Unpaid)
        ->isDirty()->toBeFalse()
        ->and($order->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->payment_status->toBe(PaymentStatus::Unpaid);
    Event::assertNotDispatched(Transitioned::class);
});

it('drops staged transitions on refresh', function () {
    $order = Order::create()->stageTransition('prepare');

    $order->refresh();
    $order->stageTransition('cancel')->save();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('writes nothing when a staged transition fails on save, and does not carry it into the next save', function (Closure $stage, string $exception) {
    $expense = Expense::create(['amount' => 4200])->transition('submit');

    $stage($expense);
    $storedStatus = $expense->fresh()->status;
    $expense->payment_reference = 'Unrelated edit';
    Event::fake([Transitioned::class]);

    expect(fn() => $expense->save())->toThrow($exception);

    expect($expense->fresh())
        ->status->toBe($storedStatus)
        ->payment_reference->toBeNull()
        ->and($expense)
        ->status->toBe(ExpenseStatus::Submitted)
        ->isDirty('status')->toBeFalse()
        ->isDirty('payment_reference')->toBeTrue();

    $expense->save();

    expect($expense->fresh())
        ->status->toBe($storedStatus)
        ->payment_reference->toBe('Unrelated edit');
    Event::assertNotDispatched(Transitioned::class);
})->with([
    'a failed guard' => [fn(Expense $expense) => $expense->stageTransition('reject', Employee::manager()), FailedGuardException::class],
    'a denied actor' => [fn(Expense $expense) => $expense->forceFill(['rejection_reason' => 'No receipt'])->stageTransition('reject', Employee::staff()), UnauthorizedTransitionException::class],
    'stale state' => [function (Expense $expense) {
        Expense::find($expense->getKey())->forceFill(['rejection_reason' => 'No receipt'])->transition('reject', Employee::manager());
        $expense->forceFill(['rejection_reason' => 'Duplicate'])->stageTransition('reject', Employee::manager());
    }, StaleStateException::class],
]);

/**
 * Stages a ship whose after-commit step fails, and a capture_payment on the other machine, to save together.
 *
 * @return ArrayObject<int, string> the after-commit and Transitioned events, in the order they are dispatched
 */
function stageShipThatFailsAfterCommitAndCapturePayment(Order $order): ArrayObject
{
    $dispatched = new ArrayObject();
    Event::listen(OrderShipped::class, fn() => throw new RuntimeException('The mail server is down.'));
    Event::listen(PaymentCaptured::class, fn() => $dispatched->append('payment captured'));
    Event::listen(Transitioned::class, fn(Transitioned $event) => $dispatched->append("transitioned: {$event->transition}"));

    $order->stageTransition(new Ship('TRACK-1'))->stageTransition('capture_payment');

    return $dispatched;
}

it('runs every after-commit step and dispatches every Transitioned before rethrowing the first failure', function (Closure $save) {
    $order = orderReadyToShip()->transition('authorize_payment');
    $dispatched = stageShipThatFailsAfterCommitAndCapturePayment($order);

    expect(fn() => $save($order))->toThrow(RuntimeException::class, 'The mail server is down.')
        ->and($dispatched->getArrayCopy())->toBe(['transitioned: ship', 'payment captured', 'transitioned: capture_payment'])
        ->and($order->fresh())
        ->status->toBe(OrderStatus::Shipped)
        ->payment_status->toBe(PaymentStatus::Paid);
})->with([
    'outside a transaction' => [fn(Order $order) => $order->save()],
    'deferred to an outer transaction\'s commit' => [fn(Order $order) => DB::transaction(fn() => $order->save())],
]);

it('rethrows the first after-commit failure when several fail', function () {
    $order = orderReadyToShip()->transition('authorize_payment');
    $dispatched = stageShipThatFailsAfterCommitAndCapturePayment($order);
    Event::listen(PaymentCaptured::class, fn() => throw new RuntimeException('The receipt printer is jammed.'));

    expect(fn() => $order->save())->toThrow(RuntimeException::class, 'The mail server is down.')
        ->and($dispatched->getArrayCopy())->toBe(['transitioned: ship', 'payment captured', 'transitioned: capture_payment']);
});
