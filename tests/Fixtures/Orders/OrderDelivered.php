<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

/**
 * Dispatched by the fluent deliver transition's after-commit step.
 */
final readonly class OrderDelivered
{
    public function __construct(public Order $order) {}
}
