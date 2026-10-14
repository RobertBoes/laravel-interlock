<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;

#[InitialStates(OrderStatus::Pending)]
class MachineWithParentTargetState extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('prepare')->from(OrderStatus::Pending)->to(OrderStatus::Preparing),
        ];
    }
}
