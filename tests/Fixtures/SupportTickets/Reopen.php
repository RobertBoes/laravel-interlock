<?php

namespace RobertBoes\Interlock\Tests\Fixtures\SupportTickets;

use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\OnSignal;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Attributes\WithoutAuthorization;
use RobertBoes\Interlock\Transition;

#[OnSignal('reply')]
#[FromStates(TicketStatus::Closed)]
#[TargetState(TicketStatus::Open)]
#[WithoutAuthorization]
final class Reopen extends Transition
{
    public function apply(Ticket $ticket): void
    {
        $ticket->reopen_count++;
    }
}
