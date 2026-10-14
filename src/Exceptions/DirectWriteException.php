<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\CompiledTransition;

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

    /**
     * @param  list<string>  $writers  the transitions that may write the guarded attribute
     */
    public static function toGuardedAttribute(Model $model, string $guardedAttribute, array $writers, ?CompiledTransition $runningTransition): self
    {
        return new self(sprintf(
            'Cannot save %s [%s]: its guarded attribute [%s] was changed %s. %s',
            $model::class,
            $model->getKey(),
            $guardedAttribute,
            $runningTransition === null
                ? 'outside a transition'
                : sprintf('during transition [%s], which may not write it', $runningTransition->name),
            self::writersOf($writers),
        ));
    }

    /**
     * @param  list<string>  $writers  the transitions that may write the guarded attribute
     */
    public static function createdWithGuardedAttribute(Model $model, string $guardedAttribute, array $writers): self
    {
        return new self(sprintf(
            'Cannot create %s with guarded attribute [%s] set: no transition runs on create. %s',
            $model::class,
            $guardedAttribute,
            self::writersOf($writers),
        ));
    }

    public static function toParentState(Model $model, CompiledMachine $machine, BackedEnum $state): self
    {
        return new self(sprintf(
            'Cannot save %s [%s]: its [%s] was set to parent state [%s], and a model is always in a leaf state. Its leaf states are [%s].',
            $model::class,
            $model->getKey(),
            $machine->stateAttribute,
            $state->value,
            self::leafStatesWithin($machine, $state),
        ));
    }

    public static function createdInParentState(Model $model, CompiledMachine $machine, BackedEnum $state): self
    {
        return new self(sprintf(
            'Cannot create %s with [%s] [%s]: it is a parent state, and a model is always in a leaf state. Its leaf states are [%s].',
            $model::class,
            $machine->stateAttribute,
            $state->value,
            self::leafStatesWithin($machine, $state),
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

    /**
     * @param  list<string>  $writers
     */
    private static function writersOf(array $writers): string
    {
        return $writers === []
            ? 'No transition may write it.'
            : sprintf('Only [%s] may write it.', implode(', ', $writers));
    }

    private static function leafStatesWithin(CompiledMachine $machine, BackedEnum $parentState): string
    {
        return implode(', ', array_map(fn(BackedEnum $leafState) => $leafState->value, $machine->states->leavesWithin($parentState)));
    }
}
