<?php

namespace RobertBoes\Interlock\Tests\Fixtures\GatedPosts;

use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;

/**
 * A post whose triggered publish only a signed-in employee may apply, so authorisation denies its trigger every time.
 */
#[UseStateMachine('status', GatedPostStatusMachine::class)]
class GatedPost extends Post
{
    protected $table = 'posts';
}
