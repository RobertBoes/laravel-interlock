<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

#[InitialStates(PostStatus::Draft)]
class MachineWithoutFromStates extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('submit')->to(PostStatus::Ready)->withoutAuthorization(),
        ];
    }
}
