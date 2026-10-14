<?php

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use RobertBoes\Interlock\AvailableTransition;
use RobertBoes\Interlock\Events\BypassedWrite;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Events\Transitioning;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Expense;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\ExpenseStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Returns\ReturnedOrder;

/**
 * @param  iterable<AvailableTransition>  $available
 * @return list<string>
 */
function availableNames(iterable $available): array
{
    return array_map(fn(AvailableTransition $transition) => $transition->name, [...$available]);
}

it('returns the transitions whose from-states include the current state, across every machine of the model', function () {
    $order = Order::create();

    expect(availableNames($order->availableTransitions(null)))->toBe(['prepare', 'cancel', 'authorise_payment']);
});

it('leaves out a transition whose guard fails, checked against the model\'s unsaved changes', function () {
    $expense = Expense::create();

    expect(availableNames($expense->availableTransitions(null)))->toBe([]);

    $expense->amount = 4200;

    expect(availableNames($expense->availableTransitions(null)))->toBe(['submit']);
});

it('leaves out a transition whose authorisation denies the actor', function (Closure $actor, array $available) {
    Gate::define('withdraw-expense', fn(Employee $employee, Expense $expense) => $employee->id === $expense->claimant_id);
    $expense = Expense::create(['amount' => 4200, 'claimant_id' => 3, 'rejection_reason' => 'No receipt'])->transition('submit');

    expect(availableNames($expense->availableTransitions($actor())))->toBe($available);
})->with([
    'a manager' => [fn() => Employee::manager(id: 5), ['approve', 'reject']],
    'the claimant' => [fn() => Employee::staff(id: 3), ['withdraw']],
    'no actor' => [fn() => null, []],
]);

it('evaluates authorisation for the authenticated user unless an actor is given, null included', function () {
    $expense = Expense::create(['amount' => 4200, 'rejection_reason' => 'No receipt'])->transition('submit');

    $this->actingAs(Employee::manager(id: 5));

    expect(availableNames($expense->availableTransitions()))->toBe(['approve', 'reject'])
        ->and(availableNames($expense->availableTransitions(null)))->toBe([]);
});

it('serialises as a list of name, state attribute and target state, for an API resource or Inertia props', function () {
    $available = orderReadyToShip()->availableTransitions(null);

    expect($available->toArray())->toBe([
        ['name' => 'ship', 'state_attribute' => 'status', 'target_state' => 'shipped'],
        ['name' => 'cancel', 'state_attribute' => 'status', 'target_state' => 'cancelled'],
        ['name' => 'authorise_payment', 'state_attribute' => 'payment_status', 'target_state' => 'authorised'],
    ])->and(JsonResource::make(['transitions' => $available])->toJson())->toBe(
        '{"transitions":[{"name":"ship","state_attribute":"status","target_state":"shipped"},{"name":"cancel","state_attribute":"status","target_state":"cancelled"},{"name":"authorise_payment","state_attribute":"payment_status","target_state":"authorised"}]}',
    );
});

it('runs no queries and dispatches no transition events while evaluating, leaving the model\'s unsaved changes as they were', function () {
    $expense = Expense::create(['amount' => 4200])->transition('submit');
    $expense->rejection_reason = 'No receipt';
    Event::fake([Transitioning::class, Transitioned::class, BypassedWrite::class]);
    DB::enableQueryLog();

    $available = $expense->availableTransitions(Employee::manager(id: 5));

    expect(availableNames($available))->toBe(['approve', 'reject'])
        ->and(DB::getQueryLog())->toBe([])
        ->and($expense->status)->toBe(ExpenseStatus::Submitted)
        ->and($expense->getDirty())->toBe(['rejection_reason' => 'No receipt'])
        ->and($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
    Event::assertNothingDispatched();
});

it('leaves out a class-based transition whose guard method fails', function () {
    $expense = Expense::create(['amount' => 250_000, 'rejection_reason' => 'Over budget'])->transition('submit');

    expect(availableNames($expense->availableTransitions(Employee::manager(id: 5))))->toBe(['reject']);
});

it('offers a transition that takes input on its from-states and authorisation, leaving its guards to when it is applied with its input', function () {
    Gate::define('pay-expense', fn(Employee $employee, Expense $expense) => $employee->role === 'finance');
    $expense = Expense::create(['amount' => 4200])->transition('submit')->transition('approve', Employee::manager(id: 5));

    expect(availableNames($expense->availableTransitions(Employee::finance())))->toBe(['pay'])
        ->and(availableNames($expense->availableTransitions(Employee::manager())))->toBe([])
        ->and(availableNames(orderReadyToShip()->availableTransitions(null)))->toBe(['ship', 'cancel', 'authorise_payment']);
});

it('offers a transition whose input the container could build without running its guards against made-up input', function () {
    expect(availableNames(ReturnedOrder::create()->availableTransitions(null)))->toBe(['return_to_sender']);
});
