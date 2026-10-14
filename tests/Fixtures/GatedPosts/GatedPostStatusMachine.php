<?php

namespace RobertBoes\Interlock\Tests\Fixtures\GatedPosts;

use Illuminate\Database\Eloquent\Builder;
use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;

/**
 * The post lifecycle, with a publish trigger whose authorisation wants an actor, which a trigger never has.
 */
#[InitialStates(PostStatus::Draft)]
#[FinalStates(PostStatus::Published)]
class GatedPostStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('submit')->from(PostStatus::Draft)->to(PostStatus::Ready),
            $this->transition('schedule')->from(PostStatus::Ready)->to(PostStatus::Scheduled),
            $this->transition('publish')
                ->from(PostStatus::Ready, PostStatus::Scheduled)
                ->to(PostStatus::Published)
                ->authorise(fn(?Employee $actor) => $actor !== null)
                ->trigger(fn(Builder $query) => $query->where('publish_at', '<=', now())),
            $this->transition('retract')->from(PostStatus::Ready, PostStatus::Scheduled)->to(PostStatus::Draft),
        ];
    }
}
