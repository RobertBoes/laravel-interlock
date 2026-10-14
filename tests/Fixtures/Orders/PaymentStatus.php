<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Authorised = 'authorised';
    case Paid = 'paid';
    case Refunded = 'refunded';
}
