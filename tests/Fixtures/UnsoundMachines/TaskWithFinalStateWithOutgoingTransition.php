<?php

namespace RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;

#[UseStateMachine('status', MachineWithFinalStateWithOutgoingTransition::class)]
class TaskWithFinalStateWithOutgoingTransition extends Model
{
    use HasStateMachines;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => TaskStatus::class];
    }
}
