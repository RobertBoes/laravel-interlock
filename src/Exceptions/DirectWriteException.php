<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Support\StateValues;

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

    public static function toStagedStateAttribute(Model $model, CompiledTransition $staged, ?BackedEnum $newState): self
    {
        return new self(sprintf(
            'Cannot save %s [%s]: its [%s] was changed to [%s] after transition [%s] was staged to move it to [%s]. Discard the staged transition instead.',
            $model::class,
            $model->getKey(),
            $staged->stateAttribute,
            $newState->value ?? 'null',
            $staged->name,
            $staged->targetState->value,
        ));
    }

    /**
     * @param  list<string>  $writers
     * @param  list<string>  $runningTransitions
     */
    public static function toGuardedAttribute(Model $model, string $guardedAttribute, array $writers, array $runningTransitions): self
    {
        return new self(sprintf(
            'Cannot save %s [%s]: its guarded attribute [%s] was changed %s. %s',
            $model::class,
            $model->getKey(),
            $guardedAttribute,
            match (count($runningTransitions)) {
                0 => 'outside a transition',
                1 => sprintf('during transition [%s], which may not write it', $runningTransitions[0]),
                default => sprintf('during transitions [%s], none of which may write it', implode(', ', $runningTransitions)),
            },
            self::writersOf($writers),
        ));
    }

    /**
     * @param  list<string>  $writers
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

    public static function toEnteredAtColumn(Model $model, string $column, string $stateAttribute): self
    {
        return new self(sprintf(
            'Cannot save %s [%s]: its [%s] records when its [%s] entered its state, and was changed outside a transition. Only Interlock may write it, unless a bypass is active.',
            $model::class,
            $model->getKey(),
            $column,
            $stateAttribute,
        ));
    }

    public static function createdWithEnteredAtColumn(Model $model, string $column, string $stateAttribute): self
    {
        return new self(sprintf(
            'Cannot create %s with [%s] set: it records when its [%s] entered its state, and only Interlock may write it, unless a bypass is active.',
            $model::class,
            $column,
            $stateAttribute,
        ));
    }

    /**
     * @param  list<string>  $stateAttributes
     * @param  list<string>  $guardedAttributes
     * @param  list<string>  $enteredAtColumns
     */
    public static function throughBuilder(Model $model, string $method, array $stateAttributes, array $guardedAttributes, array $enteredAtColumns = []): self
    {
        $attributes = array_filter([
            self::attributeList('state attribute', $stateAttributes),
            self::attributeList('guarded attribute', $guardedAttributes),
            self::attributeList('entered-at column', $enteredAtColumns),
        ]);

        return new self(sprintf(
            'Cannot %s() %s through its query builder: it writes %s, which may only change in a transition. Apply a transition to each model instead.',
            $method,
            $model::class,
            implode(' and ', $attributes),
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
            $machine->class,
            StateValues::listed($machine->initialStates),
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

    /**
     * @param  list<string>  $attributes
     */
    private static function attributeList(string $kind, array $attributes): string
    {
        if ($attributes === []) {
            return '';
        }

        return sprintf('the %s [%s]', Str::plural($kind, count($attributes)), implode(', ', $attributes));
    }

    private static function leafStatesWithin(CompiledMachine $machine, BackedEnum $parentState): string
    {
        return StateValues::listed($machine->states->leavesWithin($parentState));
    }
}
