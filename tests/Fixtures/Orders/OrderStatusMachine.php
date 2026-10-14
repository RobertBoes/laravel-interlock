<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;

#[InitialStates(OrderStatus::Pending)]
#[FinalStates(OrderStatus::Delivered, OrderStatus::Cancelled)]
class OrderStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('prepare')->from(OrderStatus::Pending)->to(OrderStatus::Packing),
            $this->transition('create_label')->from(OrderStatus::Packing)->to(OrderStatus::LabelCreated),
            $this->transition('ship')->from(OrderStatus::LabelCreated)->to(OrderStatus::Shipped),
            $this->transition('deliver')->from(OrderStatus::Shipped)->to(OrderStatus::Delivered),
            $this->transition('cancel')->from(OrderStatus::Pending, OrderStatus::Preparing)->to(OrderStatus::Cancelled),
        ];
    }
}
