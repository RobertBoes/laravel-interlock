<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Support\StateValues;

final class IllegalTransitionException extends LogicException implements InterlockException
{
    public static function currentStateNotAFromState(Model $model, CompiledTransition $transition, ?BackedEnum $currentState): self
    {
        $fromStates = StateValues::listed($transition->fromStates);

        return new self(sprintf(
            'Cannot apply transition [%s] to %s [%s]: its [%s] is [%s], and [%s] only starts from [%s].',
            $transition->name,
            $model::class,
            $model->getKey(),
            $transition->stateAttribute,
            $currentState->value ?? 'null',
            $transition->name,
            $fromStates,
        ));
    }

    public static function anotherTransitionStaged(Model $model, CompiledTransition $transition, CompiledTransition $staged): self
    {
        return new self(sprintf(
            'Cannot stage transition [%s] on %s [%s]: transition [%s] is already staged for its [%s], and a machine holds one staged transition at a time. Save the model, or discard its staged transitions, first.',
            $transition->name,
            $model::class,
            $model->getKey(),
            $staged->name,
            $transition->stateAttribute,
        ));
    }
}
