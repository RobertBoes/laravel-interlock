<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\SupportTickets\TicketStatus;

#[InitialStates(TicketStatus::Open)]
class MachineAnsweringASignalTwiceFromOneState extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('nudge')->onSignal('reply')->from(TicketStatus::Active)->to(TicketStatus::Open)->withoutAuthorization(),
            $this->transition('customer_replied')->onSignal('reply')->from(TicketStatus::Answered)->to(TicketStatus::Open)->withoutAuthorization(),
        ];
    }
}
