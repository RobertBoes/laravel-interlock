<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\GuardedAttribute;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;

#[InitialStates(OrderStatus::Pending)]
#[GuardedAttribute('tracking_code', 'shpi')]
class MachineGuardingAnAttributeForAnUnknownTransition extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('ship')->from(OrderStatus::Pending)->to(OrderStatus::Shipped),
        ];
    }
}
