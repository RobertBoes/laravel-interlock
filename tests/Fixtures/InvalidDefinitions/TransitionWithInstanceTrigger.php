<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use Illuminate\Database\Eloquent\Builder;
use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Transition;

#[FromStates(PostStatus::Ready)]
#[TargetState(PostStatus::Published)]
final class TransitionWithInstanceTrigger extends Transition
{
    /**
     * @param  Builder<Post>  $query
     */
    public function trigger(Builder $query): void
    {
        $query->where('publish_at', '<=', now());
    }
}
