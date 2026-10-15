<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use BackedEnum;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class ParentState
{
    public function __construct(public BackedEnum $state) {}
}
