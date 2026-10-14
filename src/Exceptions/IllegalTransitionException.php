<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\Graph\CompiledTransition;

final class IllegalTransitionException extends LogicException implements InterlockException
{
    public static function currentStateNotAFromState(Model $model, CompiledTransition $transition, ?BackedEnum $currentState): self
    {
        $fromStates = implode(', ', array_map(fn(BackedEnum $state) => $state->value, $transition->fromStates));

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
}
