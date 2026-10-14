<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;

#[InitialStates(PaymentStatus::Unpaid)]
#[FinalStates(PaymentStatus::Refunded)]
class PaymentStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('authorise_payment')->from(PaymentStatus::Unpaid)->to(PaymentStatus::Authorised),
            $this->transition('capture_payment')->from(PaymentStatus::Authorised)->to(PaymentStatus::Paid),
            $this->transition('refund')->from(PaymentStatus::Paid)->to(PaymentStatus::Refunded),
        ];
    }
}
