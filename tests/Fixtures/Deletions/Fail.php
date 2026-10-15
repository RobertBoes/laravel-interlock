<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Deletions;

use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\Guard;
use RobertBoes\Interlock\Attributes\SystemOnly;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Transition;

#[FromStates(DeletionStatus::Processing)]
#[TargetState(DeletionStatus::Failed)]
#[SystemOnly]
final class Fail extends Transition
{
    #[Guard('A failed deletion needs a reason.')]
    public function hasFailureReason(DeletionRequest $request): bool
    {
        return filled($request->failure_reason);
    }
}
