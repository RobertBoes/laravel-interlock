<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;

/**
 * Turns history on for the state machine it is declared on: every transition and every bypassed write to its state
 * attribute is recorded, in the same transaction as the write.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RecordsHistory {}
