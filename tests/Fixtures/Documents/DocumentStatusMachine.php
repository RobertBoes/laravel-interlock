<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Documents;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\RecordsHistory;
use RobertBoes\Interlock\StateMachine;

#[RecordsHistory]
#[InitialStates(DocumentStatus::Draft)]
#[FinalStates(DocumentStatus::Published)]
class DocumentStatusMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            $this->transition('publish')->from(DocumentStatus::Draft)->to(DocumentStatus::Published)->withoutAuthorization(),
        ];
    }
}
