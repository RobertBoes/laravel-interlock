<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\SystemOnly;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Attributes\WithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Transition;

#[FromStates(PostStatus::Draft)]
#[TargetState(PostStatus::Ready)]
#[WithoutAuthorization]
#[SystemOnly]
final class TransitionSystemOnlyAndWithoutAuthorization extends Transition {}
