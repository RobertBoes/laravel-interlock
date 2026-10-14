<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;

final readonly class CompiledTransition
{
    /**
     * @param  list<BackedEnum>  $fromStates
     */
    public function __construct(
        public string $name,
        public string $stateAttribute,
        public array $fromStates,
        public BackedEnum $targetState,
    ) {}

    public function startsFrom(?BackedEnum $state): bool
    {
        return in_array($state, $this->fromStates, true);
    }
}
