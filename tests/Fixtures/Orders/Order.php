<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;

#[UseStateMachine('status', OrderStatusMachine::class)]
class Order extends Model
{
    use HasStateMachines;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class];
    }
}
