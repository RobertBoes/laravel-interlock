<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Deletions;

enum DeletionStatus: string
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
