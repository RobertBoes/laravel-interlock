<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Contracts;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\RecordsHistory;
use RobertBoes\Interlock\StateMachine;

#[RecordsHistory]
#[InitialStates(Approval::Pending)]
#[FinalStates(Approval::Approved, Approval::Rejected)]
class LegalApprovalMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('approve_legally')->from(Approval::Pending)->to(Approval::Approved),
            $this->transition('reject_legally')->from(Approval::Pending)->to(Approval::Rejected),
        ];
    }
}
