<?php

namespace RobertBoes\Interlock\Tests\Fixtures\SupportTickets;

use RobertBoes\Interlock\Attributes\ParentState;

enum TicketStatus: string
{
    case Active = 'active';
    #[ParentState(self::Active)]
    case Open = 'open';
    #[ParentState(self::Active)]
    case Answered = 'answered';
    case Closed = 'closed';
}
