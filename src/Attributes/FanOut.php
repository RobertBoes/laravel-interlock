<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;

/**
 * Declared on a transition class's static trigger() method: each run of the trigger dispatches one job per chunk of the
 * models that match, instead of applying the transition to them itself, so a busy trigger can use several queue workers.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class FanOut {}
