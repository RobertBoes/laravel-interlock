<?php

namespace RobertBoes\Interlock\Tests\Fixtures\InvalidDefinitions;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Tests\Fixtures\SupportTickets\TicketStatus;

#[UseStateMachine('status', MachineAnsweringASignalTwiceFromOneState::class)]
class TicketAnsweringASignalTwiceFromOneState extends Model
{
    use HasStateMachines;

    protected $table = 'support_tickets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => TicketStatus::class];
    }
}
