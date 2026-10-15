<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\WithoutAuthorization;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Transition;

#[FromStates(PostStatus::Draft)]
#[WithoutAuthorization]
final class TransitionWithoutTargetState extends Transition {}
