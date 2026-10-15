<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Tickets;

use RobertBoes\Interlock\Attributes\ParentState;

enum TicketStatus: string
{
    case Active = 'active';
    #[ParentState(self::Active)]
    case Open = 'open';
    #[ParentState(self::Active)]
    case Waiting = 'waiting';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
