<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

#[InitialStates(PostStatus::Draft)]
class MachineWithTransitionAuthorizedAndWithoutAuthorization extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('submit')
                ->from(PostStatus::Draft)
                ->to(PostStatus::Ready)
                ->authorize('submit-post')
                ->withoutAuthorization(),
        ];
    }
}
