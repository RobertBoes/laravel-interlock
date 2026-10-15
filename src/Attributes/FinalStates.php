<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use BackedEnum;

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
