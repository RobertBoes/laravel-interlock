<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Tickets;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\RecordsHistory;
use RobertBoes\Interlock\Scopes\InStateFor;
use RobertBoes\Interlock\StateMachine;

#[RecordsHistory]
#[InitialStates(TicketStatus::Open)]
#[FinalStates(TicketStatus::Closed)]
class TicketStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('await_reply')->from(TicketStatus::Open)->to(TicketStatus::Waiting)->withoutAuthorization(),
            $this->transition('receive_reply')->from(TicketStatus::Waiting)->to(TicketStatus::Open)->withoutAuthorization(),
            $this->transition('remind')->from(TicketStatus::Waiting)->to(TicketStatus::Waiting)->withoutAuthorization(),
            $this->transition('resolve')->from(TicketStatus::Active)->to(TicketStatus::Resolved)->withoutAuthorization(),
            $this->transition('reopen')->from(TicketStatus::Resolved)->to(TicketStatus::Open)->withoutAuthorization(),
            $this->transition('close')
                ->from(TicketStatus::Resolved)
                ->to(TicketStatus::Closed)
                ->withoutAuthorization()
                ->trigger(new InStateFor(days: 7, stateAttribute: 'status')),
        ];
    }
}
