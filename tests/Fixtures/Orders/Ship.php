<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Transition;

#[FromStates(OrderStatus::LabelCreated)]
#[TargetState(OrderStatus::Shipped)]
final class Ship extends Transition
{
    public function __construct(public readonly string $trackingCode) {}

    public function apply(Order $order): void
    {
        $order->tracking_code = $this->trackingCode;
        $order->shipments()->create(['tracking_code' => $this->trackingCode]);
    }

    public function afterCommit(Order $order): void
    {
        event(new OrderShipped($order, $this->trackingCode));
    }
}
