<?php

namespace RobertBoes\Interlock\Tests\Fixtures\TriggerOnlyPosts;

use Illuminate\Database\Eloquent\Builder;
use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\RecordsHistory;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

/**
 * The post lifecycle, with a triggered publish whose authorization allows no one, a null actor included.
 */
#[RecordsHistory]
#[InitialStates(PostStatus::Draft)]
#[FinalStates(PostStatus::Published)]
class TriggerOnlyPostStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('submit')->from(PostStatus::Draft)->to(PostStatus::Ready),
            $this->transition('schedule')->from(PostStatus::Ready)->to(PostStatus::Scheduled),
            $this->transition('publish')
                ->from(PostStatus::Ready, PostStatus::Scheduled)
                ->to(PostStatus::Published)
                ->authorize(fn() => false)
                ->trigger(fn(Builder $query) => $query->where('publish_at', '<=', now())),
            $this->transition('retract')->from(PostStatus::Ready, PostStatus::Scheduled)->to(PostStatus::Draft),
        ];
    }
}
