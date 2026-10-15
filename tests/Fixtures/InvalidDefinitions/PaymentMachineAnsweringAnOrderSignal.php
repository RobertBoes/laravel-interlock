<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatus;

#[InitialStates(PaymentStatus::Unpaid)]
class PaymentMachineAnsweringAnOrderSignal extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('authorize_payment')->from(PaymentStatus::Unpaid)->to(PaymentStatus::Authorized)->withoutAuthorization(),
            $this->transition('capture_payment')->onSignal('ship')->from(PaymentStatus::Authorized)->to(PaymentStatus::Paid)->withoutAuthorization(),
        ];
    }
}
