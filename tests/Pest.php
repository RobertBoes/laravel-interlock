<?php

use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;
use RobertBoes\Interlock\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

function orderReadyToShip(): Order
{
    return Order::create()->transition('prepare')->transition('create_label');
}
