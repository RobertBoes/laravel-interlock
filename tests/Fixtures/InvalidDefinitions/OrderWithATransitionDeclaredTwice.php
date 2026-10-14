<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatus;

#[UseStateMachine('payment_status', MachineDeclaringATransitionTwice::class)]
class OrderWithATransitionDeclaredTwice extends Model
{
    use HasStateMachines;

    protected $table = 'orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payment_status' => PaymentStatus::class];
    }
}
