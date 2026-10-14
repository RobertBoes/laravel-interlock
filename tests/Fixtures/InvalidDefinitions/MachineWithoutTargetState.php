<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

class MachineWithoutTargetState extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('submit')->from(PostStatus::Draft),
        ];
    }
}
