<?php

namespace RobertBoes\Interlock\Persistence;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Graph\TransitionSteps;

/**
 * A transition recorded on a model instance for its next save to write: the from-state it was staged from, the steps
 * to run, the actor to authorise and, when a trigger applies it, the trigger's name, in which case the transition has
 * no actor and is not authorised.
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
