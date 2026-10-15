<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Expenses;

use Illuminate\Database\Eloquent\Builder;
use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;

#[InitialStates(ExpenseStatus::Draft)]
#[FinalStates(ExpenseStatus::Paid, ExpenseStatus::Rejected)]
class ExpenseStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('submit')
                ->from(ExpenseStatus::Draft)
                ->to(ExpenseStatus::Submitted)
                ->withoutAuthorization()
                ->guard('has_amount', fn(Expense $expense) => $expense->amount !== null, 'An expense needs an amount before it is submitted.')
                ->trigger(fn(Builder $query) => $query->where('created_at', '<=', now()->subDays(30))),
            Approve::class,
            Pay::class,
            $this->transition('reject')
                ->from(ExpenseStatus::Submitted)
                ->to(ExpenseStatus::Rejected)
                ->authorize(fn(?Employee $actor, Expense $expense) => $actor?->isManager() === true)
                ->guard('has_rejection_reason', fn(Expense $expense) => filled($expense->rejection_reason), 'Give a reason for rejecting the expense.'),
            $this->transition('withdraw')
                ->from(ExpenseStatus::Submitted)
                ->to(ExpenseStatus::Draft)
                ->authorize('withdraw-expense'),
        ];
    }
}
