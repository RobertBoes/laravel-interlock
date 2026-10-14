<?php

namespace RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;

#[InitialStates(TaskStatus::Todo)]
#[FinalStates(TaskStatus::Done, TaskStatus::Dropped)]
class MachineWithUnreachableState extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('start')->from(TaskStatus::Todo)->to(TaskStatus::Doing),
            $this->transition('finish')->from(TaskStatus::Doing)->to(TaskStatus::Done),
        ];
    }
}
