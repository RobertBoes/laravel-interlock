<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Expenses;

use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\Guard;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Transition;

#[FromStates(ExpenseStatus::Submitted)]
#[TargetState(ExpenseStatus::Approved)]
final class Approve extends Transition
{
    /** In cents. */
    private const int APPROVAL_LIMIT = 100_000;

    public function authorize(?Employee $actor, Expense $expense): bool
    {
        return $actor?->isManager() === true && $actor->id !== $expense->claimant_id;
    }

    #[Guard('An expense over 1000.00 needs approval from finance.')]
    public function withinApprovalLimit(Expense $expense): bool
    {
        return $expense->amount <= self::APPROVAL_LIMIT;
    }
}
