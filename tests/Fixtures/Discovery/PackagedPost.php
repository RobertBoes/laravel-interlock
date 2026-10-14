<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Discovery;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatusMachine;

/**
 * An interlocked model as a package or module would ship it: outside the paths the tests scan.
 */
#[UseStateMachine('status', PostStatusMachine::class)]
class PackagedPost extends Model
{
    use HasStateMachines;

    protected $table = 'posts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => PostStatus::class];
    }
}
