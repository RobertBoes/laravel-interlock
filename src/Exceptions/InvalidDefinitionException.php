<?php

namespace RobertBoes\Interlock\Exceptions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Transition;

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
     */
    public static function notATransitionClass(string $machine, string $class): self
    {
        return new self(sprintf(
            '%s lists [%s] in its transitions, but it is not a class extending %s. List fluent transitions and transition classes.',
            $machine,
            $class,
            Transition::class,
        ));
    }

    /**
     * @param  class-string<Transition>  $transition
     */
    public static function missingTargetStateAttribute(string $transition): self
    {
        return new self(sprintf(
            'Transition %s has no target state. Add #[TargetState(...)] to it with the one state it leads to.',
            $transition,
        ));
    }

    /**
     * @param  class-string<Transition>  $transition
     */
    public static function authorisedTwice(string $transition, string $ability): self
    {
        return new self(sprintf(
            'Transition %s declares both an authorise() method and #[Authorise(\'%s\')]. Keep one of them.',
            $transition,
            $ability,
        ));
    }

    /**
     * @param  class-string<Transition>  $transition
     */
    public static function triggerNotStatic(string $transition): self
    {
        return new self(sprintf(
            'Transition %s declares trigger() as an instance method. Make it static: a trigger is a query condition, checked without building the transition.',
            $transition,
        ));
    }

    /**
     * @param  class-string<Transition>  $transition
     */
    public static function missingFromStatesAttribute(string $transition): self
    {
        return new self(sprintf(
            'Transition %s has no from-states. Add #[FromStates(...)] to it with the states it may start from.',
            $transition,
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
     * @param  class-string<StateMachine>  $machine
     * @param  list<string>  $transitions  the machine's transitions
     */
    public static function guardedAttributeWrittenByUnknownTransition(string $machine, string $guardedAttribute, string $transition, array $transitions): self
    {
        return new self(sprintf(
            '%s lets [%s] write guarded attribute [%s], but has no such transition. Its transitions are [%s].',
            $machine,
            $transition,
            $guardedAttribute,
            implode(', ', $transitions),
        ));
    }

    public static function duplicateTransitionName(Model $model, string $transition, CompiledMachine $first, CompiledMachine $second): self
    {
        $declaredBy = $first->stateAttribute === $second->stateAttribute
            ? sprintf('twice in %s', $first->machine)
            : sprintf(
                'by both %s for [%s] and %s for [%s] on %s',
                $first->machine,
                $first->stateAttribute,
                $second->machine,
                $second->stateAttribute,
                $model::class,
            );

        return new self(sprintf(
            'Transition [%s] is declared %s. Transition names are unique per model, so rename one of them.',
            $transition,
            $declaredBy,
        ));
    }

    /**
     * @param  class-string<StateMachine>  $firstMachine
     * @param  class-string<StateMachine>  $secondMachine
     */
    public static function twoMachinesForOneStateAttribute(Model $model, string $stateAttribute, string $firstMachine, string $secondMachine): self
    {
        return new self(sprintf(
            '%s declares both %s and %s for [%s]. A state attribute has one state machine.',
            $model::class,
            $firstMachine,
            $secondMachine,
            $stateAttribute,
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
