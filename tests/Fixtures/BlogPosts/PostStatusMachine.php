<?php

namespace RobertBoes\Interlock\Tests\Fixtures\BlogPosts;

use RobertBoes\Interlock\StateMachine;

class PostStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('submit')->from(PostStatus::Draft)->to(PostStatus::Ready),
            $this->transition('schedule')->from(PostStatus::Ready)->to(PostStatus::Scheduled),
            $this->transition('publish')->from(PostStatus::Ready, PostStatus::Scheduled)->to(PostStatus::Published),
            $this->transition('retract')->from(PostStatus::Ready, PostStatus::Scheduled)->to(PostStatus::Draft),
        ];
    }
}
