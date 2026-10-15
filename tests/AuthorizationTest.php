<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RobertBoes\Interlock\Exceptions\InterlockException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Exceptions\UnauthorizedTransitionException;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Approve;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Expense;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\ExpenseStatus;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Pay;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithTransitionAuthorizedAndWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\MachineWithTransitionWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionAuthorizedAndWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionAuthorizedTwice;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionClassAuthorizedAndWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionClassWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TransitionAuthorizedAndWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TransitionAuthorizedTwice;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TransitionWithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\Orders\CancelOrder;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;

/**
 * @param  array<string, mixed>  $attributes
 */
function submittedExpense(array $attributes = []): Expense
{
    return Expense::create(['amount' => 4200, 'claimant_id' => 3, ...$attributes])->transition('submit');
}

it('authorizes a fluent transition with a closure that takes the actor and the model', function () {
    $expense = submittedExpense(['rejection_reason' => 'No receipt']);

    $expense->transition('reject', Employee::manager());

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Rejected);
});

it('throws when the closure denies the actor, and writes nothing', function (?Employee $actor) {
    $expense = submittedExpense(['rejection_reason' => 'No receipt']);

    expect(fn() => $expense->transition('reject', $actor))->toThrow(
        UnauthorizedTransitionException::class,
        'You may not apply transition [reject].',
    );

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted)
        ->and($expense->status)->toBe(ExpenseStatus::Submitted);
})->with([
    'a staff member' => fn() => Employee::staff(),
    'no actor' => null,
]);

it('throws an exception that extends Laravel\'s authorization exception', function () {
    $expense = submittedExpense(['rejection_reason' => 'No receipt']);

    expect(thrownBy(fn() => $expense->transition('reject', Employee::staff())))
        ->toBeInstanceOf(AuthorizationException::class)
        ->toBeInstanceOf(InterlockException::class);
});

it('authorizes a fluent transition with a Gate ability, checked for the actor with the model', function () {
    Gate::define('withdraw-expense', fn(Employee $employee, Expense $expense) => $employee->id === $expense->claimant_id
        ? Response::allow()
        : Response::deny('Only the claimant may withdraw an expense.'));
    $expense = submittedExpense(['claimant_id' => 3]);

    expect(fn() => $expense->transition('withdraw', Employee::staff(id: 4)))->toThrow(
        UnauthorizedTransitionException::class,
        'Only the claimant may withdraw an expense.',
    );

    $expense->transition('withdraw', Employee::staff(id: 3));

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Draft);
});

it('denies a Gate ability that requires a user when there is no actor', function () {
    Gate::define('withdraw-expense', fn(Employee $employee, Expense $expense) => true);
    $expense = submittedExpense();

    expect(fn() => $expense->transition('withdraw', null))->toThrow(UnauthorizedTransitionException::class);

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
});

it('keeps the status a denying Gate response gives', function () {
    Gate::define('withdraw-expense', fn(Employee $employee, Expense $expense) => Response::denyAsNotFound());
    $expense = submittedExpense();

    expect(thrownBy(fn() => $expense->transition('withdraw', Employee::staff())))
        ->toBeInstanceOf(UnauthorizedTransitionException::class)
        ->status()->toBe(404);
});

it('authorizes a class-based transition with its authorize method', function (Closure $actor, bool $allowed) {
    $expense = submittedExpense(['claimant_id' => 1]);

    $thrown = thrownBy(fn() => $expense->transition(new Approve(), $actor()));

    expect($thrown instanceof UnauthorizedTransitionException)->toBe(! $allowed)
        ->and($expense->fresh()->status)->toBe($allowed ? ExpenseStatus::Approved : ExpenseStatus::Submitted);
})->with([
    'a manager' => [fn() => Employee::manager(id: 5), true],
    'the manager who claimed it' => [fn() => Employee::manager(id: 1), false],
    'a staff member' => [fn() => Employee::staff(), false],
    'no actor' => [fn() => null, false],
]);

it('rejects a transition class that declares both an authorize method and a Gate ability', function () {
    expect(fn() => (new PostWithTransitionAuthorizedTwice())->transition('transition_authorized_twice'))->toThrow(
        InvalidDefinitionException::class,
        'Transition ' . TransitionAuthorizedTwice::class . ' declares both an authorize() method and #[Authorize(\'submit-post\')]. Keep one of them.',
    );
});

it('authorizes a class-based transition with a Gate ability declared on the class', function () {
    Gate::define('pay-expense', fn(Employee $employee, Expense $expense) => $employee->role === 'finance');
    $expense = submittedExpense()->transition(new Approve(), Employee::manager());

    expect(fn() => $expense->transition(new Pay('PAY-1'), Employee::manager()))->toThrow(UnauthorizedTransitionException::class);

    $expense->transition(new Pay('PAY-1'), Employee::finance());

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Paid);
});

it('lets anyone apply a fluent transition without authorization, a guest and a null actor included', function (Closure $apply) {
    $expense = Expense::create(['amount' => 4200]);

    $apply($expense);

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
})->with([
    'a staff member' => [fn(Expense $expense) => $expense->transition('submit', Employee::staff())],
    'a guest' => [fn(Expense $expense) => $expense->transition('submit')],
    'a null actor' => [fn(Expense $expense) => $expense->transition('submit', null)],
]);

it('lets anyone apply a transition class marked #[WithoutAuthorization], a guest and a null actor included', function (Closure $apply) {
    $order = Order::create();

    $apply($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
})->with([
    'a staff member' => [fn(Order $order) => $order->transition(new CancelOrder(), Employee::staff())],
    'a guest' => [fn(Order $order) => $order->transition('cancel')],
    'a null actor' => [fn(Order $order) => $order->transition('cancel', null)],
]);

it('rejects a fluent transition that declares no authorization and no trigger', function () {
    expect(fn() => (new PostWithTransitionWithoutAuthorization())->transition('submit'))->toThrow(
        InvalidDefinitionException::class,
        'Transition [submit] in ' . MachineWithTransitionWithoutAuthorization::class . ' declares no authorization, so no one may apply it. Call ->authorize() with who may apply it, ->withoutAuthorization() to let anyone apply it, or ->trigger() to let only its trigger apply it.',
    );
});

it('rejects a transition class that declares no authorization and no trigger', function () {
    expect(fn() => (new PostWithTransitionClassWithoutAuthorization())->transition('transition_without_authorization'))->toThrow(
        InvalidDefinitionException::class,
        'Transition ' . TransitionWithoutAuthorization::class . ' declares no authorization, so no one may apply it. Add an authorize() method or #[Authorize(...)] with who may apply it, #[WithoutAuthorization] to let anyone apply it, or a static trigger() method to let only its trigger apply it.',
    );
});

it('rejects a fluent transition that declares both authorization and withoutAuthorization()', function () {
    expect(fn() => (new PostWithTransitionAuthorizedAndWithoutAuthorization())->transition('submit'))->toThrow(
        InvalidDefinitionException::class,
        'Transition [submit] in ' . MachineWithTransitionAuthorizedAndWithoutAuthorization::class . ' calls both ->authorize() and ->withoutAuthorization(). Keep one of them.',
    );
});

it('rejects a transition class that declares both authorization and #[WithoutAuthorization]', function () {
    expect(fn() => (new PostWithTransitionClassAuthorizedAndWithoutAuthorization())->transition('transition_authorized_and_without_authorization'))->toThrow(
        InvalidDefinitionException::class,
        'Transition ' . TransitionAuthorizedAndWithoutAuthorization::class . ' declares both authorization and #[WithoutAuthorization]. Keep one of them.',
    );
});

it('applies the transition on behalf of the authenticated user by default', function () {
    $expense = submittedExpense(['rejection_reason' => 'No receipt']);

    $this->actingAs(Employee::staff());
    expect(fn() => $expense->transition('reject'))->toThrow(UnauthorizedTransitionException::class);

    $this->actingAs(Employee::manager());
    $expense->transition('reject');

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Rejected);
});

it('applies the transition on behalf of the actor given, instead of the authenticated user', function () {
    $expense = submittedExpense(['rejection_reason' => 'No receipt']);

    $this->actingAs(Employee::manager());
    expect(fn() => $expense->transition('reject', null))->toThrow(UnauthorizedTransitionException::class)
        ->and(fn() => $expense->transition('reject', Employee::staff()))->toThrow(UnauthorizedTransitionException::class);

    $this->actingAs(Employee::staff());
    $expense->transition('reject', Employee::manager());

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Rejected);
});

it('authorizes the actor before checking guards', function () {
    $expense = submittedExpense(['rejection_reason' => null]);

    expect(fn() => $expense->transition('reject', Employee::staff()))->toThrow(UnauthorizedTransitionException::class);
});

it('authorizes the actor outside the transition\'s transaction', function () {
    $levels = [];
    Gate::define('withdraw-expense', function (Employee $employee) use (&$levels) {
        $levels[] = DB::transactionLevel();

        return true;
    });
    $expense = submittedExpense();
    $outside = DB::transactionLevel();

    $expense->transition('withdraw', Employee::staff());

    expect($levels)->toBe([$outside]);
});
