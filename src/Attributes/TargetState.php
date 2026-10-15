<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use BackedEnum;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TargetState
{
    public function __construct(public BackedEnum $state) {}
}
