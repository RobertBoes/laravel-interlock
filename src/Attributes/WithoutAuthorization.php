<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;

/**
 * Anyone may apply the transition by hand, guests and a null actor included. Declare this or authorization, not both.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class WithoutAuthorization {}
