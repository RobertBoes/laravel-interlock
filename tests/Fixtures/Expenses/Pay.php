<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Expenses;

use RobertBoes\Interlock\Attributes\Authorize;
use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\Guard;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Transition;

#[FromStates(ExpenseStatus::Approved)]
#[TargetState(ExpenseStatus::Paid)]
#[Authorize('pay-expense')]
final class Pay extends Transition
{
    public function __construct(public readonly string $paymentReference) {}

    #[Guard('A payment needs a reference.')]
    public function hasReference(): bool
    {
        return $this->paymentReference !== '';
    }

    public function apply(Expense $expense): void
    {
        $expense->payment_reference = $this->paymentReference;
    }
}
