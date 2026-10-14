<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Returns;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;

#[InitialStates(OrderStatus::Pending)]
#[FinalStates(OrderStatus::Cancelled)]
class ReturnedOrderStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [ReturnToSender::class];
    }
}
