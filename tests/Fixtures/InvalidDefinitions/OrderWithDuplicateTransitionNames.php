<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatus;

#[UseStateMachine('status', OrderStatusMachine::class)]
#[UseStateMachine('payment_status', PaymentMachineReusingATransitionName::class)]
class OrderWithDuplicateTransitionNames extends Model
{
    use HasStateMachines;

    protected $table = 'orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
        ];
    }
}
