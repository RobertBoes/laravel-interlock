<?php

namespace RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;

#[InitialStates(TaskStatus::Todo)]
#[FinalStates(TaskStatus::Done, TaskStatus::Dropped)]
class MachineWithDuplicateTransitionNames extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('start')->from(TaskStatus::Todo)->to(TaskStatus::Doing)->withoutAuthorization(),
            $this->transition('finish')->from(TaskStatus::Doing)->to(TaskStatus::Done)->withoutAuthorization(),
            $this->transition('drop')->from(TaskStatus::Todo)->to(TaskStatus::Dropped)->withoutAuthorization(),
            $this->transition('start')->from(TaskStatus::Doing)->to(TaskStatus::Todo)->withoutAuthorization(),
        ];
    }
}
