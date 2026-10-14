<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

/**
 * Dispatched by Ship's after-commit step, where an app would send its "your order is on its way" mail.
 */
final readonly class OrderShipped
{
    public function __construct(
        public Order $order,
        public string $trackingCode,
    ) {}
}
