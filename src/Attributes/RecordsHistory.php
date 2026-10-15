<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;

/**
 * Records every transition and every bypassed write to the state attribute, in the same transaction as the write.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RecordsHistory {}
