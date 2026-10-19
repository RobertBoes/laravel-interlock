<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Documents;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
