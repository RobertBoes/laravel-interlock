<?php

namespace RobertBoes\Interlock\Tests\MisdeclaredModels;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatusMachine;

/**
 * Declares a machine but forgets HasStateMachines. Kept outside Fixtures/, because scanning a path that holds it fails.
 */
#[UseStateMachine('status', PostStatusMachine::class)]
class PostWithoutHasStateMachines extends Model
{
    protected $table = 'posts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => PostStatus::class];
    }
}
