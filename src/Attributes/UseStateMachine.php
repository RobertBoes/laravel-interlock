<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use RobertBoes\Interlock\StateMachine;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class UseStateMachine
{
    /**
     * @param  class-string<StateMachine>  $machine
     * @param  string|null  $enteredAt  the column Interlock sets whenever the state attribute enters a state
     */
    public function __construct(
        public string $stateAttribute,
        public string $machine,
        public ?string $enteredAt = null,
    ) {}
}
