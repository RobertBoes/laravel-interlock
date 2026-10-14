<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RobertBoes\Interlock\Exceptions\InterlockException;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Exceptions\UnauthorisedTransitionException;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Approve;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Expense;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\ExpenseStatus;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Pay;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\PostWithTransitionAuthorisedTwice;
use RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions\TransitionAuthorisedTwice;

/**
 * @param  array<string, mixed>  $attributes
 */
function submittedExpense(array $attributes = []): Expense
{
    return Expense::create(['amount' => 4200, 'claimant_id' => 3, ...$attributes])->transition('submit');
}

it('authorises a fluent transition with a closure that takes the actor and the model', function () {
    $expense = submittedExpense(['rejection_reason' => 'No receipt']);

    $expense->transition('reject', Employee::manager());

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Rejected);
});

it('throws when the closure denies the actor, and writes nothing', function (?Employee $actor) {
    $expense = submittedExpense(['rejection_reason' => 'No receipt']);

    expect(fn() => $expense->transition('reject', $actor))->toThrow(
        UnauthorisedTransitionException::class,
        'You may not apply transition [reject].',
    );

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted)
        ->and($expense->status)->toBe(ExpenseStatus::Submitted);
})->with([
    'a staff member' => fn() => Employee::staff(),
    'no actor' => null,
]);

it('throws an exception that extends Laravel\'s authorisation exception', function () {
    $expense = submittedExpense(['rejection_reason' => 'No receipt']);

    expect(thrownBy(fn() => $expense->transition('reject', Employee::staff())))
        ->toBeInstanceOf(AuthorizationException::class)
        ->toBeInstanceOf(InterlockException::class);
});

it('authorises a fluent transition with a Gate ability, checked for the actor with the model', function () {
    Gate::define('withdraw-expense', fn(Employee $employee, Expense $expense) => $employee->id === $expense->claimant_id
        ? Response::allow()
        : Response::deny('Only the claimant may withdraw an expense.'));
    $expense = submittedExpense(['claimant_id' => 3]);

    expect(fn() => $expense->transition('withdraw', Employee::staff(id: 4)))->toThrow(
        UnauthorisedTransitionException::class,
        'Only the claimant may withdraw an expense.',
    );

    $expense->transition('withdraw', Employee::staff(id: 3));

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Draft);
});

it('denies a Gate ability that requires a user when there is no actor', function () {
    Gate::define('withdraw-expense', fn(Employee $employee, Expense $expense) => true);
    $expense = submittedExpense();

    expect(fn() => $expense->transition('withdraw', null))->toThrow(UnauthorisedTransitionException::class);

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
});

it('keeps the status a denying Gate response gives', function () {
    Gate::define('withdraw-expense', fn(Employee $employee, Expense $expense) => Response::denyAsNotFound());
    $expense = submittedExpense();

    expect(thrownBy(fn() => $expense->transition('withdraw', Employee::staff())))
        ->toBeInstanceOf(UnauthorisedTransitionException::class)
        ->status()->toBe(404);
});

it('authorises a class-based transition with its authorise method', function (Closure $actor, bool $allowed) {
    $expense = submittedExpense(['claimant_id' => 1]);

    $thrown = thrownBy(fn() => $expense->transition(new Approve(), $actor()));

    expect($thrown instanceof UnauthorisedTransitionException)->toBe(! $allowed)
        ->and($expense->fresh()->status)->toBe($allowed ? ExpenseStatus::Approved : ExpenseStatus::Submitted);
})->with([
    'a manager' => [fn() => Employee::manager(id: 5), true],
    'the manager who claimed it' => [fn() => Employee::manager(id: 1), false],
    'a staff member' => [fn() => Employee::staff(), false],
    'no actor' => [fn() => null, false],
]);

it('rejects a transition class that declares both an authorise method and a Gate ability', function () {
    expect(fn() => (new PostWithTransitionAuthorisedTwice())->transition('transition_authorised_twice'))->toThrow(
        InvalidDefinitionException::class,
        'Transition ' . TransitionAuthorisedTwice::class . ' declares both an authorise() method and #[Authorise(\'submit-post\')]. Keep one of them.',
    );
});

it('authorises a class-based transition with a Gate ability declared on the class', function () {
    Gate::define('pay-expense', fn(Employee $employee, Expense $expense) => $employee->role === 'finance');
    $expense = submittedExpense()->transition(new Approve(), Employee::manager());

    expect(fn() => $expense->transition(new Pay('PAY-1'), Employee::manager()))->toThrow(UnauthorisedTransitionException::class);

    $expense->transition(new Pay('PAY-1'), Employee::finance());

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Paid);
});

it('allows a transition without declared authorisation for every actor, null included', function (?Employee $actor) {
    $expense = Expense::create(['amount' => 4200]);

    $expense->transition('submit', $actor);

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
})->with([
    'a staff member' => fn() => Employee::staff(),
    'no actor' => null,
]);

it('applies the transition on behalf of the authenticated user by default', function () {
    $expense = submittedExpense(['rejection_reason' => 'No receipt']);

    $this->actingAs(Employee::staff());
    expect(fn() => $expense->transition('reject'))->toThrow(UnauthorisedTransitionException::class);

    $this->actingAs(Employee::manager());
    $expense->transition('reject');

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Rejected);
});

it('applies the transition on behalf of the actor given, instead of the authenticated user', function () {
    $expense = submittedExpense(['rejection_reason' => 'No receipt']);

    $this->actingAs(Employee::manager());
    expect(fn() => $expense->transition('reject', null))->toThrow(UnauthorisedTransitionException::class)
        ->and(fn() => $expense->transition('reject', Employee::staff()))->toThrow(UnauthorisedTransitionException::class);

    $this->actingAs(Employee::staff());
    $expense->transition('reject', Employee::manager());

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Rejected);
});

it('authorises the actor before checking guards', function () {
    $expense = submittedExpense(['rejection_reason' => null]);

    expect(fn() => $expense->transition('reject', Employee::staff()))->toThrow(UnauthorisedTransitionException::class);
});

it('authorises the actor outside the transition\'s transaction', function () {
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
