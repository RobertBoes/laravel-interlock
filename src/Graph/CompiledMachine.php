<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;
use RobertBoes\Interlock\StateMachine;

final readonly class CompiledMachine
{
    /**
     * @param  class-string<StateMachine>  $class
     * @param  non-empty-list<BackedEnum>  $initialStates
     * @param  list<BackedEnum>  $finalStates  as declared, parent states included
     */
    public function __construct(
        public string $class,
        public string $stateAttribute,
        public array $initialStates,
        public StateTree $states,
        public array $finalStates,
        public bool $recordsHistory,
    ) {}

    public function isInitialState(BackedEnum $state): bool
    {
        return in_array($state, $this->initialStates, true);
    }

    public function soleInitialState(): ?BackedEnum
    {
        return count($this->initialStates) === 1 ? $this->initialStates[0] : null;
    }

    public function isFinalState(BackedEnum $state): bool
    {
        return array_any($this->finalStates, fn(BackedEnum $finalState) => $this->states->isWithin($state, $finalState));
    }
}
