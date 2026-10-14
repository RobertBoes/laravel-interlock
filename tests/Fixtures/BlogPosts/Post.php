<?php

namespace RobertBoes\Interlock\Tests\Fixtures\BlogPosts;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;

#[UseStateMachine('status', PostStatusMachine::class)]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;
    use HasStateMachines;

    protected $guarded = [];

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'publish_at' => 'datetime',
        ];
    }
}
