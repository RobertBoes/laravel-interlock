<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Exports;

enum ClaimStatus: int
{
    case Open = 0;
    case Assessing = 1;
    case Settled = 2;
}
