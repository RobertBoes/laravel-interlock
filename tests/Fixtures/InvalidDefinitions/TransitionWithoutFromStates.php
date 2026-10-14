<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Transition;

#[TargetState(PostStatus::Ready)]
final class TransitionWithoutFromStates extends Transition {}
