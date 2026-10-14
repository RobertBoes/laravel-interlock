<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Expenses;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;

/**
 * @property ?int $claimant_id
 * @property ?int $amount
 * @property ?string $rejection_reason
 * @property ?string $payment_reference
 */
#[UseStateMachine('status', ExpenseStatusMachine::class)]
class Expense extends Model
{
    use HasStateMachines;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => ExpenseStatus::class];
    }
}
