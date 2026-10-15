<?php

namespace RobertBoes\Interlock\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RuntimeException;

final class CancelledTransitionException extends RuntimeException implements InterlockException
{
    public static function saveCancelled(Model $model, CompiledTransition $transition): self
    {
        return new self(sprintf(
            'Cannot apply transition [%s] to %s [%s]: a saving or updating listener cancelled the save, so nothing was written and its [%s] is still [%s].',
            $transition->name,
            $model::class,
            $model->getKey(),
            $transition->stateAttribute,
            $model->getAttribute($transition->stateAttribute)->value ?? 'null',
        ));
    }
}
