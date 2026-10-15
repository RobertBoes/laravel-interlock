<?php

namespace RobertBoes\Interlock\Tests\Fixtures\LockedPosts;

use Illuminate\Database\Eloquent\Builder;
use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\GuardedAttribute;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

/**
 * The "scheduled and locked" pattern from the README: once scheduled, a post's only way out is the triggered publish,
 * which declares no authorization so only its trigger applies it, and only schedule may write its publish_at.
 */
#[InitialStates(PostStatus::Draft)]
#[FinalStates(PostStatus::Published)]
#[GuardedAttribute('publish_at', 'schedule')]
class LockedPostStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('submit')->from(PostStatus::Draft)->to(PostStatus::Ready)->withoutAuthorization(),
            $this->transition('schedule')->from(PostStatus::Ready)->to(PostStatus::Scheduled)->withoutAuthorization(),
            $this->transition('publish')
                ->from(PostStatus::Scheduled)
                ->to(PostStatus::Published)
                ->trigger(fn(Builder $query) => $query->where('publish_at', '<=', now())),
        ];
    }
}
