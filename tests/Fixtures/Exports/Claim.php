<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Exports;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\HasStateMachines;

/**
 * @property string|null $assessment
 * @property int $amount
 */
#[UseStateMachine('status', ClaimStatusMachine::class)]
class Claim extends Model
{
    use HasStateMachines;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => ClaimStatus::class];
    }
}
