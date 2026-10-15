<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;

/**
 * The signal the transition answers instead of its own name. A transition that takes input cannot declare one.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class OnSignal
{
    public function __construct(public string $signal) {}
}
