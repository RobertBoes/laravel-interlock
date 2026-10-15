<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Tickets;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\HasStateScopes;

/**
 * @property ?Carbon $status_changed_at
 * @property ?CarbonImmutable $priority_changed_at
 */
#[UseStateMachine('status', TicketStatusMachine::class, enteredAt: 'status_changed_at')]
#[UseStateMachine('priority', TicketPriorityMachine::class, enteredAt: 'priority_changed_at')]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;
    use HasStateMachines;
    use HasStateScopes;

    protected $guarded = [];

    protected static function newFactory(): TicketFactory
    {
        return TicketFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'priority_changed_at' => 'immutable_datetime',
        ];
    }
}
