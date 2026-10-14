<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Orders;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\HasStateScopes;

/**
 * An order that opts into Interlock's Eloquent scope methods.
 */
#[UseStateMachine('status', OrderStatusMachine::class)]
class ScopedOrder extends Model
{
    use HasStateMachines;
    use HasStateScopes;

    protected $table = 'orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class];
    }
}
