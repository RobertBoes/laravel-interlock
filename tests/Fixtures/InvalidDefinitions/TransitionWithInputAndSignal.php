<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\OnSignal;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Attributes\WithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Transition;

#[OnSignal('go_live')]
#[FromStates(PostStatus::Ready)]
#[TargetState(PostStatus::Published)]
#[WithoutAuthorization]
final class TransitionWithInputAndSignal extends Transition
{
    public function __construct(public readonly string $channel) {}
}
