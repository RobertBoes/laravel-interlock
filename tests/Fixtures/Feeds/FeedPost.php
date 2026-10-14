<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Feeds;

use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Attributes\UseStateMachine;

/**
 * A post from a high-volume feed, whose publish trigger fans out over several queue workers.
 */
#[UseStateMachine('status', FeedPostStatusMachine::class)]
class FeedPost extends Post
{
    protected $table = 'posts';
}
