<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;

#[InitialStates(OrderStatus::Pending, OrderStatus::Preparing)]
class MachineWithParentInitialState extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('create_label')->from(OrderStatus::Packing)->to(OrderStatus::LabelCreated),
        ];
    }
}
