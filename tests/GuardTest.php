<?php

use Illuminate\Support\Facades\Gate;
use RobertBoes\Interlock\Exceptions\FailedGuardException;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Approve;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Expense;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\ExpenseStatus;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Pay;

it('throws the guard\'s message when a fluent transition\'s guard fails, and writes nothing', function () {
    $expense = Expense::create();

    expect(fn() => $expense->transition('submit'))->toThrow(
        FailedGuardException::class,
        'An expense needs an amount before it is submitted.',
    );

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Draft)
        ->and($expense->status)->toBe(ExpenseStatus::Draft);
});

it('checks guards against the model\'s unsaved changes', function () {
    $expense = Expense::create();
    $expense->amount = 4200;

    $expense->transition('submit');

    expect($expense->fresh())
        ->status->toBe(ExpenseStatus::Submitted)
        ->amount->toBe(4200);
});

it('keeps the model\'s unsaved changes in memory when a guard fails', function () {
    $expense = Expense::create(['amount' => 4200])->transition('submit');
    $expense->rejection_reason = '';

    expect(fn() => $expense->transition('reject', Employee::manager()))->toThrow(FailedGuardException::class, 'Give a reason for rejecting the expense.');

    expect($expense)
        ->status->toBe(ExpenseStatus::Submitted)
        ->rejection_reason->toBe('')
        ->isDirty('rejection_reason')->toBeTrue()
        ->and($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
});

it('throws the guard\'s message when a class-based transition\'s guard method fails, naming the guard after the method', function () {
    $expense = Expense::create(['amount' => 250_000])->transition('submit');

    $exception = thrownBy(fn() => $expense->transition(new Approve(), Employee::manager()));

    expect($exception)
        ->toBeInstanceOf(FailedGuardException::class)
        ->getMessage()->toBe('An expense over 1000.00 needs approval from finance.')
        ->transition->toBe('approve')
        ->guard->toBe('within_approval_limit')
        ->and($expense->fresh()->status)->toBe(ExpenseStatus::Submitted);
});

it('applies a class-based transition whose guards hold', function () {
    $expense = Expense::create(['amount' => 4200])->transition('submit');

    $expense->transition(new Approve(), Employee::manager());

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Approved);
});

it('gives a class-based transition\'s guards its typed input', function () {
    Gate::define('pay-expense', fn(Employee $employee) => true);
    $expense = Expense::create(['amount' => 4200])->transition('submit')->transition(new Approve(), Employee::manager());

    expect(fn() => $expense->transition(new Pay(''), Employee::finance()))->toThrow(FailedGuardException::class, 'A payment needs a reference.');

    $expense->transition(new Pay('PAY-1'), Employee::finance());

    expect($expense->fresh())
        ->status->toBe(ExpenseStatus::Paid)
        ->payment_reference->toBe('PAY-1');
});
