<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatus;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatusMachine;

#[UseStateMachine('payment_status', PaymentStatusMachine::class)]
#[UseStateMachine('payment_status', PaymentMachineReusingATransitionName::class)]
class OrderWithTwoMachinesForOneAttribute extends Model
{
    use HasStateMachines;

    protected $table = 'orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payment_status' => PaymentStatus::class];
    }
}
