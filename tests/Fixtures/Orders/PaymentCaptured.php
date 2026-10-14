<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

/**
 * Dispatched by capture_payment's after-commit step, where an app would send the receipt.
 */
final readonly class PaymentCaptured
{
    public function __construct(public Order $order) {}
}
