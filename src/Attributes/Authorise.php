<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;

/**
 * Authorises a class-based transition with a Gate ability, checked for the actor with the model. Declare either this
 * or an authorise($actor, $model) method on the transition class, not both.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Authorise
{
    public function __construct(public string $ability) {}
}
