<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\ParentState;

enum StatusWithParentOfAnotherEnum: string
{
    case Open = 'open';
    #[ParentState(OtherStatus::Open)]
    case Reviewing = 'reviewing';
}
