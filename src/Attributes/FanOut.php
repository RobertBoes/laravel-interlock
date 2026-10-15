<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;

/**
 * On trigger(): each run dispatches one job per chunk of matching models instead of applying the transition itself.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class FanOut {}
