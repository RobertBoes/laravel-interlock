<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use BackedEnum;

/**
 * Nests an enum case under a parent state of the same enum, such as packing within preparing.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class ParentState
{
    public function __construct(public BackedEnum $state) {}
}
