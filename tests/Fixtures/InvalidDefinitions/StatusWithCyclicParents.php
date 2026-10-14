<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\ParentState;

enum StatusWithCyclicParents: string
{
    case Open = 'open';
    #[ParentState(self::Approving)]
    case Reviewing = 'reviewing';
    #[ParentState(self::Reviewing)]
    case Approving = 'approving';
}
