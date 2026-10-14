<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\StateMachine;

final class InvalidDefinitionException extends LogicException implements InterlockException
{
    /**
     * @param  class-string<StateMachine>  $machine
     */
    public static function missingTargetState(string $machine, string $transition): self
    {
        return new self(sprintf(
            'Transition [%s] in %s has no target state. Call ->to() with the one state it leads to.',
            $transition,
            $machine,
        ));
    }

    /**
     * @param  class-string<StateMachine>  $machine
     */
    public static function missingFromStates(string $machine, string $transition): self
    {
        return new self(sprintf(
            'Transition [%s] in %s has no from-states. Call ->from() with the states it may start from.',
            $transition,
            $machine,
        ));
    }

    /**
     * @param  class-string<StateMachine>  $machine
     * @param  list<BackedEnum>  $leafStates
     */
    public static function targetStateIsParentState(string $machine, string $transition, BackedEnum $state, array $leafStates): self
    {
        return new self(sprintf(
            'Transition [%s] in %s targets parent state [%s]. A model is always in a leaf state, so target one of [%s] instead.',
            $transition,
            $machine,
            $state->value,
            implode(', ', array_map(fn(BackedEnum $leafState) => $leafState->value, $leafStates)),
        ));
    }

    /**
     * @param  class-string<StateMachine>  $firstMachine
     * @param  class-string<StateMachine>  $secondMachine
     */
    public static function duplicateTransitionName(Model $model, string $transition, string $firstMachine, string $secondMachine): self
    {
        return new self(sprintf(
            '%s has two transitions named [%s], in %s. Transition names are unique per model, so rename one of them.',
            $model::class,
            $transition,
            implode(' and ', array_unique([$firstMachine, $secondMachine])),
        ));
    }

    /**
     * @param  class-string<StateMachine>  $machine
     */
    public static function missingInitialStates(string $machine): self
    {
        return new self(sprintf(
            '%s declares no initial states. Add #[InitialStates(...)] to it with the states a model may be created in.',
            $machine,
        ));
    }

    /**
     * @param  class-string<StateMachine>  $machine
     */
    public static function initialStateNotOfCastEnum(string $machine, Model $model, string $stateAttribute, BackedEnum $state, string $castEnum): self
    {
        return new self(sprintf(
            '%s declares initial state [%s] of %s, but %s casts [%s] to %s.',
            $machine,
            $state->value,
            $state::class,
            $model::class,
            $stateAttribute,
            $castEnum,
        ));
    }

    /**
     * @param  class-string<StateMachine>  $machine
     */
    public static function finalStateNotOfCastEnum(string $machine, Model $model, string $stateAttribute, BackedEnum $state, string $castEnum): self
    {
        return new self(sprintf(
            '%s declares final state [%s] of %s, but %s casts [%s] to %s.',
            $machine,
            $state->value,
            $state::class,
            $model::class,
            $stateAttribute,
            $castEnum,
        ));
    }

    /**
     * @param  class-string<StateMachine>  $machine
     * @param  list<BackedEnum>  $leafStates
     */
    public static function initialStateIsParentState(string $machine, BackedEnum $state, array $leafStates): self
    {
        return new self(sprintf(
            '%s declares parent state [%s] as an initial state. A model is always in a leaf state, so declare one of [%s] instead.',
            $machine,
            $state->value,
            implode(', ', array_map(fn(BackedEnum $leafState) => $leafState->value, $leafStates)),
        ));
    }

    /**
     * @param  class-string<StateMachine>  $machine
     */
    public static function stateAttributeNotCast(string $machine, Model $model, string $stateAttribute, BackedEnum $state): self
    {
        return new self(sprintf(
            '%s uses states of %s, so %s must cast [%s] to %s in its casts.',
            $machine,
            $state::class,
            $model::class,
            $stateAttribute,
            $state::class,
        ));
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function parentStateOfAnotherEnum(string $enum, string $case, BackedEnum $parentState): self
    {
        return new self(sprintf(
            '%s::%s declares parent state [%s] of %s. A parent state must be a case of the same enum.',
            $enum,
            $case,
            $parentState->value,
            $parentState::class,
        ));
    }

    /**
     * @param  list<BackedEnum>  $path  from the state, through its parent states, back to itself
     */
    public static function stateNestedWithinItself(BackedEnum $state, array $path): self
    {
        return new self(sprintf(
            '%s::%s is nested within itself: [%s]. Remove one of these #[ParentState] attributes.',
            $state::class,
            $state->name,
            implode(', ', array_map(fn(BackedEnum $pathState) => $pathState->value, $path)),
        ));
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function registeredModelWithoutStateMachine(string $model): self
    {
        return new self(sprintf(
            '%s is registered with Interlock, but has no #[UseStateMachine] attribute.',
            $model,
        ));
    }
}
