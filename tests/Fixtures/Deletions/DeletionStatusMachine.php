<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Deletions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\RecordsHistory;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\Employee;

#[RecordsHistory]
#[InitialStates(DeletionStatus::Requested)]
#[FinalStates(DeletionStatus::Completed, DeletionStatus::Failed, DeletionStatus::Cancelled)]
class DeletionStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('start')
                ->from(DeletionStatus::Requested)
                ->to(DeletionStatus::Processing)
                ->systemOnly()
                ->trigger(fn(Builder $query) => $query->where('process_after', '<=', now())),
            $this->transition('complete')->from(DeletionStatus::Processing)->to(DeletionStatus::Completed)->systemOnly(),
            Fail::class,
            $this->transition('cancel')
                ->from(DeletionStatus::Requested)
                ->to(DeletionStatus::Cancelled)
                ->authorize(fn(?Authenticatable $actor) => $actor instanceof Employee && $actor->isManager()),
        ];
    }
}
