<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

#[InitialStates(PostStatus::Draft)]
class MachineListingANonTransitionClass extends StateMachine
{
    public function transitions(): array
    {
        /** @phpstan-ignore return.type (deliberately lists a class that is not a transition) */
        return [Post::class];
    }
}
