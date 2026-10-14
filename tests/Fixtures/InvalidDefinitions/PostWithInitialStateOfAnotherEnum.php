<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatus;

#[UseStateMachine('status', MachineWithInitialStateOfAnotherEnum::class)]
class PostWithInitialStateOfAnotherEnum extends Model
{
    use HasStateMachines;

    protected $table = 'posts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => PostStatus::class];
    }
}
