<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

use RobertBoes\Interlock\Attributes\ParentState;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    #[ParentState(self::Preparing)]
    case Packing = 'packing';
    #[ParentState(self::Preparing)]
    case LabelCreated = 'label_created';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}
