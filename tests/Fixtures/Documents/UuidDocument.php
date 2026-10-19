<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Documents;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;

/**
 * @property DocumentStatus $status
 */
#[UseStateMachine('status', DocumentStatusMachine::class)]
class UuidDocument extends Model
{
    use HasUuids;
    use HasStateMachines;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => DocumentStatus::class];
    }
}
