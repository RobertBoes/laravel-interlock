<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use BackedEnum;

/**
 * The states a model is meant to end in, declared on its state machine. A final state has no outgoing transitions.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class FinalStates
{
    /** @var list<BackedEnum> */
    public array $states;

    public function __construct(BackedEnum ...$states)
    {
        $this->states = array_values($states);
    }
}
