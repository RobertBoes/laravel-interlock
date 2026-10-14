<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\Graph\CompiledMachine;

final class DirectWriteException extends LogicException implements InterlockException
{
    public static function toStateAttribute(Model $model, string $stateAttribute, ?BackedEnum $storedState, ?BackedEnum $newState): self
    {
        return new self(sprintf(
            'Cannot save %s [%s]: its [%s] was changed from [%s] to [%s] outside a transition. Apply a transition instead.',
            $model::class,
            $model->getKey(),
            $stateAttribute,
            $storedState->value ?? 'null',
            $newState->value ?? 'null',
        ));
    }

    public static function notAnInitialState(Model $model, CompiledMachine $machine, BackedEnum $state): self
    {
        return new self(sprintf(
            'Cannot create %s with [%s] [%s]: it is not an initial state of %s. Its initial states are [%s].',
            $model::class,
            $machine->stateAttribute,
            $state->value,
            $machine->machine,
            implode(', ', array_map(fn(BackedEnum $initialState) => $initialState->value, $machine->initialStates)),
        ));
    }
}
