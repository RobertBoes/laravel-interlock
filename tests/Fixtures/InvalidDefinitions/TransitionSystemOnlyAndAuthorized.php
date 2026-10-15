<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use Illuminate\Contracts\Auth\Authenticatable;
use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\SystemOnly;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Transition;

#[FromStates(PostStatus::Draft)]
#[TargetState(PostStatus::Ready)]
#[SystemOnly]
final class TransitionSystemOnlyAndAuthorized extends Transition
{
    public function authorize(?Authenticatable $actor, Post $post): bool
    {
        return true;
    }
}
