<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Deletions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;

/**
 * @property DeletionStatus $status
 * @property ?Carbon $process_after
 * @property ?string $failure_reason
 */
#[UseStateMachine('status', DeletionStatusMachine::class)]
class DeletionRequest extends Model
{
    use HasStateMachines;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => DeletionStatus::class, 'process_after' => 'datetime'];
    }
}
