<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use BackedEnum;

/**
 * The states a model may be created in, declared on its state machine.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class InitialStates
{
    /** @var list<BackedEnum> */
    public array $states;

    public function __construct(BackedEnum ...$states)
    {
        $this->states = array_values($states);
    }
}
