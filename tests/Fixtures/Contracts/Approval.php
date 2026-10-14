<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Contracts;

enum Approval: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
