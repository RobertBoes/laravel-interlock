<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;

/**
 * Only system code, inside Interlock::asSystem(), or the transition's trigger applies it. Declare this or authorization,
 * not both.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SystemOnly {}
