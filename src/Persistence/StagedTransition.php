<?php

namespace RobertBoes\Interlock\Persistence;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Graph\TransitionSteps;

/**
 * When a trigger applies the transition, it has no actor and is not authorised.
 */
final readonly class StagedTransition
{
    public function __construct(
        public CompiledTransition $compiled,
        public TransitionSteps $steps,
        public BackedEnum $fromState,
        public ?Authenticatable $actor,
        public ?string $trigger = null,
    ) {}
}
