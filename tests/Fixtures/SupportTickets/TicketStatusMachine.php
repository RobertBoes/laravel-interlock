<?php

namespace RobertBoes\Interlock\Tests\Fixtures\SupportTickets;

use Illuminate\Contracts\Auth\Authenticatable;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\RecordsHistory;
use RobertBoes\Interlock\StateMachine;

#[RecordsHistory]
#[InitialStates(TicketStatus::Open)]
class TicketStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('answer')
                ->from(TicketStatus::Open)
                ->to(TicketStatus::Answered)
                ->authorize(fn(?Authenticatable $actor) => $actor !== null),
            $this->transition('customer_replied')
                ->onSignal('reply')
                ->from(TicketStatus::Answered)
                ->to(TicketStatus::Open)
                ->withoutAuthorization()
                ->guard('has_message', fn(Ticket $ticket) => filled($ticket->last_message), 'A reply needs a message.'),
            $this->transition('close')->from(TicketStatus::Active)->to(TicketStatus::Closed)->withoutAuthorization(),
            Reopen::class,
        ];
    }
}
