<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatus;

#[InitialStates(PaymentStatus::Unpaid)]
class MachineDeclaringATransitionTwice extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('pay')->from(PaymentStatus::Unpaid)->to(PaymentStatus::Authorised),
            $this->transition('pay')->from(PaymentStatus::Authorised)->to(PaymentStatus::Paid),
        ];
    }
}
