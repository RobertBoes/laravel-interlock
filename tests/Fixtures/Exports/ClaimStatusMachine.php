<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Exports;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;

#[InitialStates(ClaimStatus::Open)]
#[FinalStates(ClaimStatus::Settled)]
class ClaimStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('assess')->from(ClaimStatus::Open)->to(ClaimStatus::Assessing)->withoutAuthorization(),
            $this->transition('settle')
                ->from(ClaimStatus::Assessing)
                ->to(ClaimStatus::Settled)
                ->withoutAuthorization()
                ->guard('has_assessment', fn(Claim $claim) => $claim->assessment !== null, 'A claim needs an assessment before it is settled.')
                ->guard('within_policy_limit', fn(Claim $claim) => $claim->amount <= 500_000, 'A claim over the policy limit cannot be settled.'),
        ];
    }
}
