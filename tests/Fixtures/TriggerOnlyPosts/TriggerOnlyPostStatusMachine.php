<?php

namespace RobertBoes\Interlock\Tests\Fixtures\TriggerOnlyPosts;

use Illuminate\Database\Eloquent\Builder;
use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\RecordsHistory;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

/**
 * The post lifecycle, with a triggered publish that declares no authorization, so no one may apply it by hand.
 */
#[RecordsHistory]
#[InitialStates(PostStatus::Draft)]
#[FinalStates(PostStatus::Published)]
class TriggerOnlyPostStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('submit')->from(PostStatus::Draft)->to(PostStatus::Ready)->withoutAuthorization(),
            $this->transition('schedule')->from(PostStatus::Ready)->to(PostStatus::Scheduled)->withoutAuthorization(),
            $this->transition('publish')
                ->from(PostStatus::Ready, PostStatus::Scheduled)
                ->to(PostStatus::Published)
                ->trigger(fn(Builder $query) => $query->where('publish_at', '<=', now())),
            $this->transition('retract')->from(PostStatus::Ready, PostStatus::Scheduled)->to(PostStatus::Draft)->withoutAuthorization(),
        ];
    }
}
