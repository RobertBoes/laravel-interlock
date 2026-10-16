<?php

namespace RobertBoes\Interlock\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\Graph\CompiledTransition;

final class IllegalTransitionException extends LogicException implements InterlockException
{
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

    public static function withoutTrigger(Model $model, CompiledTransition $transition): self
    {
        return new self(sprintf(
            'Cannot apply transition [%s] on %s [%s] as a trigger: it declares no trigger. Apply it with transition(), which checks its authorization.',
            $transition->name,
            $model::class,
            $model->getKey(),
        ));
    }
}
