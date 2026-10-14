<?php

namespace RobertBoes\Interlock\Persistence;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Graph\TransitionSteps;

/**
 * A transition recorded on a model instance for its next save to write: the from-state it was staged from, the steps
 * to run and the actor to authorise.
 */
final readonly class StagedTransition
{
    public function __construct(
        public CompiledTransition $transition,
        public TransitionSteps $steps,
        public BackedEnum $fromState,
        public ?Authenticatable $actor,
    ) {}
}
