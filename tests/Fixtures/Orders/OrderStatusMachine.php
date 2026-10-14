<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\GuardedAttribute;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;

#[InitialStates(OrderStatus::Pending)]
#[FinalStates(OrderStatus::Delivered, OrderStatus::Cancelled)]
#[GuardedAttribute('tracking_code', Ship::class)]
class OrderStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('prepare')->from(OrderStatus::Pending)->to(OrderStatus::Packing),
            $this->transition('create_label')->from(OrderStatus::Packing)->to(OrderStatus::LabelCreated),
            Ship::class,
            $this->transition('deliver')
                ->from(OrderStatus::Shipped)
                ->to(OrderStatus::Delivered)
                ->apply(fn(Order $order) => $order->delivered_at = now())
                ->afterCommit(fn(Order $order) => event(new OrderDelivered($order))),
            CancelOrder::class,
        ];
    }
}
