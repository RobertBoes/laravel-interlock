<?php

namespace RobertBoes\Interlock\Tests\Fixtures\BlogPosts;

enum PostStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Scheduled = 'scheduled';
    case Published = 'published';
}
