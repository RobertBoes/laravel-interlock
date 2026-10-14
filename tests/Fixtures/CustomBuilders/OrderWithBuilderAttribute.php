<?php

namespace RobertBoes\Interlock\Tests\Fixtures\CustomBuilders;

use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatusMachine;

/**
 * An order that sets its custom builder with Laravel's builder attribute.
 */
#[UseStateMachine('status', OrderStatusMachine::class)]
#[UseEloquentBuilder(OrderBuilder::class)]
class OrderWithBuilderAttribute extends Model
{
    use HasStateMachines;

    protected $table = 'orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class];
    }
}
