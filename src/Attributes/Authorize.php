<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;

/**
 * A Gate ability, checked for the actor with the model. Declare this or an authorize() method, not both.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Authorize
{
    public function __construct(public string $ability) {}
}
