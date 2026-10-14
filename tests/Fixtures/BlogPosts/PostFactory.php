<?php

namespace RobertBoes\Interlock\Tests\Fixtures\BlogPosts;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'title' => 'A post',
        ];
    }
}
