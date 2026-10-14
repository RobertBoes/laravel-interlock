<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;
use RobertBoes\Interlock\StateMachine;

final readonly class CompiledMachine
{
    /**
     * @param  class-string<StateMachine>  $machine
     * @param  non-empty-list<BackedEnum>  $initialStates
     */
    public function __construct(
        public string $machine,
        public string $stateAttribute,
        public array $initialStates,
    ) {}

    public function isInitialState(BackedEnum $state): bool
    {
        return in_array($state, $this->initialStates, true);
    }
}
