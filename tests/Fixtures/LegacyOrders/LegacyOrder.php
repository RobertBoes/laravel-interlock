<?php

namespace RobertBoes\Interlock\Tests\Fixtures\LegacyOrders;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;

#[UseStateMachine('status', OrderStatusMachine::class)]
class LegacyOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class];
    }
}
