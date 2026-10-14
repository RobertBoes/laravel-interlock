<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Transition;

#[FromStates(PostStatus::Draft)]
final class TransitionWithoutTargetState extends Transition {}
