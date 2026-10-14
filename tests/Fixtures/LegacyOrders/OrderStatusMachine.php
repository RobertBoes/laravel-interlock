<?php

namespace RobertBoes\Interlock\Tests\Fixtures\LegacyOrders;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;

/**
 * Shares its class name with the order domain's machine, so both export under the same machine ID.
 */
#[InitialStates(OrderStatus::Pending)]
#[FinalStates(OrderStatus::Delivered)]
class OrderStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('deliver')->from(OrderStatus::Pending)->to(OrderStatus::Delivered),
        ];
    }
}
