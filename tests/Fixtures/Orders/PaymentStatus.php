<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Refunded = 'refunded';
}
