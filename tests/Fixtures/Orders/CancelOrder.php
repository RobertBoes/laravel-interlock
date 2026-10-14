<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Transition;

/**
 * A class-based transition without input or steps, named cancel rather than after its class.
 */
#[FromStates(OrderStatus::Pending, OrderStatus::Preparing)]
#[TargetState(OrderStatus::Cancelled)]
final class CancelOrder extends Transition
{
    public static function name(): string
    {
        return 'cancel';
    }
}
