<?php

use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Exceptions\DirectWriteException;
use RobertBoes\Interlock\Exceptions\IllegalTransitionException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Exceptions\StaleStateException;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\Contracts\Approval;
use RobertBoes\Interlock\Tests\Fixtures\Contracts\Contract;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineDeclaringATransitionTwice;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\OrderWithATransitionDeclaredTwice;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\OrderWithDuplicateTransitionNames;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\OrderWithTwoMachinesForOneAttribute;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PaymentMachineReusingATransitionName;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatusMachine;

it('starts each of a model\'s machines in its own initial state', function () {
    $order = Order::create();

    expect($order->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->payment_status->toBe(PaymentStatus::Unpaid);
});

it('discovers each of a model\'s machines, keyed by state attribute', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Orders']]);

    expect(Interlock::models()[Order::class])->toBe([
        'status' => OrderStatusMachine::class,
        'payment_status' => PaymentStatusMachine::class,
    ]);
});

it('rejects creating a model with a state that is not an initial state of that attribute\'s machine', function () {
    expect(fn() => Order::create(['payment_status' => PaymentStatus::Paid]))->toThrow(
        DirectWriteException::class,
        'Cannot create ' . Order::class . ' with [payment_status] [paid]: it is not an initial state of ' . PaymentStatusMachine::class . '. Its initial states are [unpaid].',
    );

    expect(Order::count())->toBe(0);
});

it('applies a transition to the machine that declares it, by name alone', function () {
    $order = Order::create();
    Event::fake([Transitioned::class]);

    $order->transition('authorise_payment');

    expect($order->fresh())
        ->payment_status->toBe(PaymentStatus::Authorised)
        ->status->toBe(OrderStatus::Pending);
    Event::assertDispatched(fn(Transitioned $event) => $event->transition === 'authorise_payment'
        && $event->fromState === PaymentStatus::Unpaid
        && $event->targetState === PaymentStatus::Authorised);
});

it('checks a transition\'s from-states against its own machine\'s state attribute', function () {
    $order = Order::create()->transition('prepare');

    expect(fn() => $order->transition('capture_payment'))->toThrow(
        IllegalTransitionException::class,
        'Cannot apply transition [capture_payment] to ' . Order::class . ' [' . $order->getKey() . ']: its [payment_status] is [unpaid], and [capture_payment] only starts from [authorised].',
    );
});

it('lists the transitions of every machine when a transition is unknown', function () {
    expect(fn() => Order::create()->transition('pay'))->toThrow(
        UnknownTransitionException::class,
        Order::class . ' has no transition named [pay]. Its transitions are [prepare, create_label, ship, deliver, cancel, authorise_payment, capture_payment, refund].',
    );
});

it('rejects a direct write to one machine\'s state attribute while another machine transitions', function () {
    $order = Order::create();
    $order->payment_status = PaymentStatus::Paid;

    expect(fn() => $order->transition('prepare'))->toThrow(
        DirectWriteException::class,
        'Cannot save ' . Order::class . ' [' . $order->getKey() . ']: its [payment_status] was changed from [unpaid] to [paid] outside a transition. Apply a transition instead.',
    );

    expect($order->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->payment_status->toBe(PaymentStatus::Unpaid);
});

it('compares and swaps only the state attribute of the transition\'s machine', function () {
    $order = Order::create();
    $first = Order::find($order->getKey());
    $second = Order::find($order->getKey());

    $first->transition('authorise_payment');
    $second->transition('prepare');

    expect($order->fresh())
        ->payment_status->toBe(PaymentStatus::Authorised)
        ->status->toBe(OrderStatus::Packing);
});

it('throws stale state when another instance transitioned the same machine first', function () {
    $order = Order::create();
    $first = Order::find($order->getKey());
    $second = Order::find($order->getKey());

    $first->transition('prepare');
    $second->transition('authorise_payment');

    expect(rescue(fn() => $second->transition('cancel'), fn(Throwable $e) => $e, report: false))
        ->toBeInstanceOf(StaleStateException::class)
        ->context()->toBe([
            'model' => Order::class,
            'key' => $order->getKey(),
            'transition' => 'cancel',
            'state_attribute' => 'status',
            'loaded_state' => 'pending',
            'stored_state' => 'packing',
            'row_deleted' => false,
        ]);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Packing)
        ->payment_status->toBe(PaymentStatus::Authorised);
});

it('rejects a transition name declared by two of a model\'s machines', function () {
    expect(fn() => (new OrderWithDuplicateTransitionNames())->transition('authorise_payment'))->toThrow(
        InvalidDefinitionException::class,
        'Transition [cancel] is declared by both ' . OrderStatusMachine::class . ' for [status] and ' . PaymentMachineReusingATransitionName::class . ' for [payment_status] on ' . OrderWithDuplicateTransitionNames::class . '. Transition names are unique per model, so rename one of them.',
    );
});

it('rejects a transition name declared twice in one machine', function () {
    expect(fn() => (new OrderWithATransitionDeclaredTwice())->transition('pay'))->toThrow(
        InvalidDefinitionException::class,
        'Transition [pay] is declared twice in ' . MachineDeclaringATransitionTwice::class . '. Transition names are unique per model, so rename one of them.',
    );
});

it('rejects two machines for one state attribute', function () {
    expect(fn() => (new OrderWithTwoMachinesForOneAttribute())->transition('authorise_payment'))->toThrow(
        InvalidDefinitionException::class,
        OrderWithTwoMachinesForOneAttribute::class . ' declares both ' . PaymentStatusMachine::class . ' and ' . PaymentMachineReusingATransitionName::class . ' for [payment_status]. A state attribute has one state machine.',
    );
});

it('backs two machines with one enum, each following its own graph', function () {
    $contract = Contract::create();

    $contract->transition('reject_legally')->transition('reject_budget')->transition('reopen_budget');

    expect($contract->fresh())
        ->legal_status->toBe(Approval::Rejected)
        ->finance_status->toBe(Approval::Pending);
});

it('does not apply one machine\'s transition to another machine backed by the same enum', function () {
    $contract = Contract::create()->transition('reject_legally');

    expect(fn() => $contract->transition('reopen_budget'))->toThrow(
        IllegalTransitionException::class,
        'Cannot apply transition [reopen_budget] to ' . Contract::class . ' [' . $contract->getKey() . ']: its [finance_status] is [pending], and [reopen_budget] only starts from [rejected].',
    );

    expect($contract->fresh())
        ->legal_status->toBe(Approval::Rejected)
        ->finance_status->toBe(Approval::Pending);
});

it('rejects a direct write to one of two state attributes backed by the same enum', function () {
    $contract = Contract::create()->transition('approve_legally');
    $contract->finance_status = Approval::Approved;

    expect(fn() => $contract->save())->toThrow(
        DirectWriteException::class,
        'Cannot save ' . Contract::class . ' [' . $contract->getKey() . ']: its [finance_status] was changed from [pending] to [approved] outside a transition. Apply a transition instead.',
    );

    expect($contract->fresh()->finance_status)->toBe(Approval::Pending);
});
