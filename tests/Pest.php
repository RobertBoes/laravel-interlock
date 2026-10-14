<?php

use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

function orderReadyToShip(): Order
{
    return Order::create()->transition('prepare')->transition('create_label');
}

/**
 * Runs the callback and returns what it threw, or null when it threw nothing.
 */
function thrownBy(Closure $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    return null;
}
