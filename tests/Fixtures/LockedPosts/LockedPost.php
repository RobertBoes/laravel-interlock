<?php

namespace RobertBoes\Interlock\Tests\Fixtures\LockedPosts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

/**
 * @property ?Carbon $publish_at
 */
#[UseStateMachine('status', LockedPostStatusMachine::class)]
class LockedPost extends Model
{
    use HasStateMachines;

    protected $table = 'posts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'publish_at' => 'datetime',
        ];
    }
}
