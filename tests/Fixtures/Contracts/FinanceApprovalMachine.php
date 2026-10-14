<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Contracts;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;

#[InitialStates(Approval::Pending)]
#[FinalStates(Approval::Approved)]
class FinanceApprovalMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('approve_budget')->from(Approval::Pending)->to(Approval::Approved),
            $this->transition('reject_budget')->from(Approval::Pending)->to(Approval::Rejected),
            $this->transition('reopen_budget')->from(Approval::Rejected)->to(Approval::Pending),
        ];
    }
}
