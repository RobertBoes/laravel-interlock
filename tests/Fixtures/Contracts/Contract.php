<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Contracts;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;

#[UseStateMachine('legal_status', LegalApprovalMachine::class)]
#[UseStateMachine('finance_status', FinanceApprovalMachine::class)]
class Contract extends Model
{
    use HasStateMachines;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'legal_status' => Approval::class,
            'finance_status' => Approval::class,
        ];
    }
}
