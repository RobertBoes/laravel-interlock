<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use BackedEnum;

/**
 * The states a class-based transition may start from, declared on the transition class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class FromStates
{
    /** @var list<BackedEnum> */
    public array $states;

    public function __construct(BackedEnum ...$states)
    {
        $this->states = array_values($states);
    }
}
