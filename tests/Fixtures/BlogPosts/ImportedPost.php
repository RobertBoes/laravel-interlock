<?php

namespace RobertBoes\Interlock\Tests\Fixtures\BlogPosts;

use RobertBoes\Interlock\Attributes\UseStateMachine;

#[UseStateMachine('status', ImportedPostStatusMachine::class)]
class ImportedPost extends Post
{
    protected $table = 'posts';
}
