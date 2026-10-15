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
            $this->transition('authorize_payment')->from(PaymentStatus::Unpaid)->to(PaymentStatus::Authorized),
            $this->transition('capture_payment')
                ->from(PaymentStatus::Authorized)
                ->to(PaymentStatus::Paid)
                ->afterCommit(fn(Order $order) => event(new PaymentCaptured($order))),
            $this->transition('refund')->from(PaymentStatus::Paid)->to(PaymentStatus::Refunded),
        ];
    }
}
