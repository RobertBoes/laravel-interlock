<?php

namespace RobertBoes\Interlock\Tests\Fixtures\TriggerOnlyPosts;

use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;

/**
 * A post whose publish only its trigger applies: the transition's authorization allows no one.
 */
#[UseStateMachine('status', TriggerOnlyPostStatusMachine::class)]
class TriggerOnlyPost extends Post
{
    protected $table = 'posts';
}
