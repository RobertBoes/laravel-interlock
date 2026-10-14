<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatus;

#[InitialStates(PaymentStatus::Unpaid)]
class PaymentMachineReusingATransitionName extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('authorise_payment')->from(PaymentStatus::Unpaid)->to(PaymentStatus::Authorised),
            $this->transition('cancel')->from(PaymentStatus::Authorised)->to(PaymentStatus::Unpaid),
        ];
    }
}
