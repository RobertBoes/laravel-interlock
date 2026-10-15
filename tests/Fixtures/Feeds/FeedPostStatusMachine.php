<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Feeds;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\RecordsHistory;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

/**
 * The post lifecycle, with a publish trigger that dispatches one job per chunk of the posts that are due. Its
 * condition is an invokable scope class rather than a closure.
 */
#[RecordsHistory]
#[InitialStates(PostStatus::Draft)]
#[FinalStates(PostStatus::Published)]
class FeedPostStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('submit')->from(PostStatus::Draft)->to(PostStatus::Ready)->withoutAuthorization(),
            $this->transition('schedule')->from(PostStatus::Ready)->to(PostStatus::Scheduled)->withoutAuthorization(),
            $this->transition('publish')
                ->from(PostStatus::Ready, PostStatus::Scheduled)
                ->to(PostStatus::Published)
                ->trigger(new PublishAtHasPassed(), fanOut: true),
            $this->transition('retract')->from(PostStatus::Ready, PostStatus::Scheduled)->to(PostStatus::Draft)->withoutAuthorization(),
        ];
    }
}
