<?php

namespace RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;

#[UseStateMachine('status', MachineWithUnreachableState::class)]
class TaskWithUnreachableState extends Model
{
    use HasStateMachines;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => TaskStatus::class];
    }
}
