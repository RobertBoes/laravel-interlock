<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;

final readonly class CompiledTransition
{
    /**
     * @param  list<BackedEnum>  $fromStates  as declared, parent states included
     * @param  list<BackedEnum>  $leafFromStates  the from-states with each parent state expanded to the leaf states within it
     */
    public function __construct(
        public string $name,
        public string $stateAttribute,
        public array $fromStates,
        public BackedEnum $targetState,
        public array $leafFromStates,
    ) {}

    public function startsFrom(?BackedEnum $state): bool
    {
        return in_array($state, $this->leafFromStates, true);
    }
}
