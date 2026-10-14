<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

use Illuminate\Database\Eloquent\Builder;
use RobertBoes\Interlock\Attributes\FanOut;
use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Transition;

/**
 * A class-based transition without input or steps, named cancel rather than after its class. Its trigger cancels
 * orders that stay unpaid for three days, and fans out.
 */
#[FromStates(OrderStatus::Pending, OrderStatus::Preparing)]
#[TargetState(OrderStatus::Cancelled)]
final class CancelOrder extends Transition
{
    public static function name(): string
    {
        return 'cancel';
    }

    /**
     * @param  Builder<Order>  $query
     */
    #[FanOut]
    public static function trigger(Builder $query): void
    {
        $query->where('payment_status', PaymentStatus::Unpaid)->where('created_at', '<=', now()->subDays(3));
    }
}
