<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Tickets;

enum TicketPriority: string
{
    case Normal = 'normal';
    case Urgent = 'urgent';
}
