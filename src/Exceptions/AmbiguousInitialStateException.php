<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\Graph\CompiledMachine;

final class AmbiguousInitialStateException extends LogicException implements InterlockException
{
    public static function noStateGiven(Model $model, CompiledMachine $machine): self
    {
        return new self(sprintf(
            'Cannot create %s without a [%s]: %s has several initial states [%s]. Set the one it starts in.',
            $model::class,
            $machine->stateAttribute,
            $machine->machine,
            implode(', ', array_map(fn(BackedEnum $state) => $state->value, $machine->initialStates)),
        ));
    }
}
