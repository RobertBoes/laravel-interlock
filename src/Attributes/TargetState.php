<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use BackedEnum;

/**
 * The one state a class-based transition leads to, declared on the transition class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TargetState
{
    public function __construct(public BackedEnum $state) {}
}
