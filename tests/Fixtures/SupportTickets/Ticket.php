<?php

namespace RobertBoes\Interlock\Tests\Fixtures\SupportTickets;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;

/**
 * @property ?string $last_message
 * @property int $reopen_count
 */
#[UseStateMachine('status', TicketStatusMachine::class)]
class Ticket extends Model
{
    use HasStateMachines;

    protected $table = 'support_tickets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => TicketStatus::class];
    }
}
