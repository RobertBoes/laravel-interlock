<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use RobertBoes\Interlock\StateMachine;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class UseStateMachine
{
    /**
     * @param  class-string<StateMachine>  $machine
     */
    public function __construct(
        public string $attribute,
        public string $machine,
    ) {}
}
