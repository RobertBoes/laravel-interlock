<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Tickets;

use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;

#[InitialStates(TicketPriority::Normal)]
class TicketPriorityMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('escalate')->from(TicketPriority::Normal)->to(TicketPriority::Urgent)->withoutAuthorization(),
            $this->transition('deescalate')->from(TicketPriority::Urgent)->to(TicketPriority::Normal)->withoutAuthorization(),
        ];
    }
}
