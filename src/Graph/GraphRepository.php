<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use RobertBoes\Interlock\Attributes\Authorize;
use RobertBoes\Interlock\Attributes\FanOut;
use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\GuardedAttribute;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\OnSignal;
use RobertBoes\Interlock\Attributes\RecordsHistory;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\Attributes\WithoutAuthorization;
use RobertBoes\Interlock\Definition\FluentTransition;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Support\PhpAttribute;
use RobertBoes\Interlock\Transition;

final class GraphRepository
{
    /** @var array<class-string<Model>, ModelGraph> */
    private array $graphs = [];

    /** @var array<class-string<BackedEnum>, StateTree> */
    private array $stateTrees = [];

    public function for(Model $model): ModelGraph
    {
        return $this->graphs[$model::class] ??= $this->compile($model);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public function stateTree(string $enum): StateTree
    {
        return $this->stateTrees[$enum] ??= StateTree::of($enum);
    }

    private function compile(Model $model): ModelGraph
    {
        $machines = [];
        $transitions = [];
        $signals = [];
        $guardedAttributes = [];
        $guardedAttributeMachines = [];

        foreach ((new ReflectionClass($model))->getAttributes(UseStateMachine::class) as $attribute) {
            $declaration = $attribute->newInstance();

            if (isset($machines[$declaration->stateAttribute])) {
                throw InvalidDefinitionException::twoMachinesForOneStateAttribute($model, $declaration->stateAttribute, $machines[$declaration->stateAttribute]->class, $declaration->machine);
            }

            $machine = $machines[$declaration->stateAttribute] = $this->compileMachine($model, $declaration);
            $machineTransitions = [];

            foreach ((new $declaration->machine())->transitions() as $definition) {
                $transition = is_string($definition)
                    ? $this->compileTransitionClass($model, $machine, $definition)
                    : $this->compileFluentTransition($model, $machine, $definition);

                if (isset($transitions[$transition->name])) {
                    throw InvalidDefinitionException::duplicateTransitionName($model, $transition->name, $machines[$transitions[$transition->name]->stateAttribute], $machine);
                }

                $transitions[$transition->name] = $machineTransitions[] = $transition;
            }

            self::rejectSignalsAnsweredTwiceFromOneState($machine, $machineTransitions);

            foreach ($machineTransitions as $transition) {
                $otherMachinesTransition = $signals[$transition->signal][0] ?? null;

                if ($otherMachinesTransition !== null && $otherMachinesTransition->stateAttribute !== $machine->stateAttribute) {
                    throw InvalidDefinitionException::signalOnTwoMachines($model, $transition->signal, $otherMachinesTransition, $machines[$otherMachinesTransition->stateAttribute], $transition, $machine);
                }

                $signals[$transition->signal][] = $transition;
            }

            foreach ($this->compileGuardedAttributes($machine, $machineTransitions) as $attribute => $writers) {
                $guardedAttributes[$attribute] = [...$guardedAttributes[$attribute] ?? [], ...$writers];
                $guardedAttributeMachines[$attribute][] = $machine->stateAttribute;
            }
        }

        return new ModelGraph($model::class, $machines, $transitions, $signals, $guardedAttributes, $guardedAttributeMachines);
    }

    /**
     * Parent states are expanded, so the state alone decides which transition a signal applies.
     *
     * @param  list<CompiledTransition>  $transitions
     */
    private static function rejectSignalsAnsweredTwiceFromOneState(CompiledMachine $machine, array $transitions): void
    {
        $answering = [];

        foreach ($transitions as $transition) {
            foreach ($transition->leafFromStates as $state) {
                $first = $answering[$transition->signal][$state->value] ?? null;

                if ($first !== null) {
                    throw InvalidDefinitionException::signalAnsweredTwiceFromOneState($machine->class, $transition->signal, $state, $first->name, $transition->name);
                }

                $answering[$transition->signal][$state->value] = $transition;
            }
        }
    }

    private function compileMachine(Model $model, UseStateMachine $declaration): CompiledMachine
    {
        $reflection = new ReflectionClass($declaration->machine);
        $initialStates = PhpAttribute::of($reflection, InitialStates::class)->states ?? [];

        if ($initialStates === []) {
            throw InvalidDefinitionException::missingInitialStates($declaration->machine);
        }

        $castEnum = $model->getCasts()[$declaration->stateAttribute]
            ?? throw InvalidDefinitionException::stateAttributeNotCast($declaration->machine, $model, $declaration->stateAttribute, $initialStates[0]);
        $initialStateOfAnotherEnum = self::stateNotOf($castEnum, $initialStates);

        if ($initialStateOfAnotherEnum !== null) {
            throw InvalidDefinitionException::initialStateNotOfCastEnum($declaration->machine, $model, $declaration->stateAttribute, $initialStateOfAnotherEnum, $castEnum);
        }

        $states = $this->stateTree($initialStates[0]::class);
        $parentInitialState = array_find($initialStates, fn(BackedEnum $state) => $states->isParent($state));

        if ($parentInitialState !== null) {
            throw InvalidDefinitionException::initialStateIsParentState($declaration->machine, $parentInitialState, $states->leavesWithin($parentInitialState));
        }

        $finalStates = PhpAttribute::of($reflection, FinalStates::class)->states ?? [];
        $finalStateOfAnotherEnum = self::stateNotOf($castEnum, $finalStates);

        if ($finalStateOfAnotherEnum !== null) {
            throw InvalidDefinitionException::finalStateNotOfCastEnum($declaration->machine, $model, $declaration->stateAttribute, $finalStateOfAnotherEnum, $castEnum);
        }

        $recordsHistory = $reflection->getAttributes(RecordsHistory::class) !== [];

        return new CompiledMachine($declaration->machine, $declaration->stateAttribute, $initialStates, $states, $finalStates, $recordsHistory, $declaration->enteredAt);
    }

    /**
     * @param  list<CompiledTransition>  $transitions
     * @return array<string, list<string>>
     */
    private function compileGuardedAttributes(CompiledMachine $machine, array $transitions): array
    {
        $guardedAttributes = [];

        foreach ((new ReflectionClass($machine->class))->getAttributes(GuardedAttribute::class) as $attribute) {
            $declaration = $attribute->newInstance();

            $guardedAttributes[$declaration->attribute] = array_map(
                fn(string $writer) => $this->transitionNamed($machine, $declaration->attribute, $writer, $transitions),
                $declaration->transitions,
            );
        }

        return $guardedAttributes;
    }

    /**
     * @param  string|class-string<Transition>  $nameOrClass
     * @param  list<CompiledTransition>  $transitions
     */
    private function transitionNamed(CompiledMachine $machine, string $guardedAttribute, string $nameOrClass, array $transitions): string
    {
        $transition = array_find(
            $transitions,
            fn(CompiledTransition $transition) => $transition->name === $nameOrClass || $transition->class === $nameOrClass,
        );

        if ($transition === null) {
            throw InvalidDefinitionException::guardedAttributeWrittenByUnknownTransition(
                $machine->class,
                $guardedAttribute,
                $nameOrClass,
                array_map(fn(CompiledTransition $transition) => $transition->name, $transitions),
            );
        }

        return $transition->name;
    }

    private function compileFluentTransition(Model $model, CompiledMachine $machine, FluentTransition $definition): CompiledTransition
    {
        if ($definition->targetState === null) {
            throw InvalidDefinitionException::missingTargetState($machine->class, $definition->name);
        }

        if ($definition->fromStates === []) {
            throw InvalidDefinitionException::missingFromStates($machine->class, $definition->name);
        }

        $authorized = $definition->authorization !== null;

        if ($authorized && $definition->anyoneMayApply) {
            throw InvalidDefinitionException::authorizedAndWithoutAuthorization($machine->class, $definition->name);
        }

        $triggerOnly = ! $authorized && ! $definition->anyoneMayApply;

        if ($triggerOnly && $definition->trigger === null) {
            throw InvalidDefinitionException::missingAuthorization($machine->class, $definition->name);
        }

        return $this->compileTransition(
            $model,
            $machine,
            $definition->name,
            $definition->signal,
            $definition->fromStates,
            $definition->targetState,
            null,
            TransitionSteps::fluent($definition),
            $definition->trigger,
            $definition->triggerFansOut,
            $triggerOnly,
        );
    }

    /**
     * @param  class-string<Transition>  $class
     */
    private function compileTransitionClass(Model $model, CompiledMachine $machine, string $class): CompiledTransition
    {
        if (! is_subclass_of($class, Transition::class)) {
            throw InvalidDefinitionException::notATransitionClass($machine->class, $class);
        }

        $reflection = new ReflectionClass($class);
        $fromStates = PhpAttribute::of($reflection, FromStates::class)->states ?? [];
        $targetState = PhpAttribute::of($reflection, TargetState::class)->state
            ?? throw InvalidDefinitionException::missingTargetStateAttribute($class);

        if ($fromStates === []) {
            throw InvalidDefinitionException::missingFromStatesAttribute($class);
        }

        $ability = PhpAttribute::of($reflection, Authorize::class)?->ability;
        $hasAuthorizeMethod = $reflection->hasMethod('authorize');

        if ($ability !== null && $hasAuthorizeMethod) {
            throw InvalidDefinitionException::authorizedTwice($class, $ability);
        }

        $authorized = $ability !== null || $hasAuthorizeMethod;
        $anyoneMayApply = $reflection->getAttributes(WithoutAuthorization::class) !== [];

        if ($authorized && $anyoneMayApply) {
            throw InvalidDefinitionException::classAuthorizedAndWithoutAuthorization($class);
        }

        $trigger = $this->triggerOf($reflection);
        $triggerOnly = ! $authorized && ! $anyoneMayApply;

        if ($triggerOnly && $trigger === null) {
            throw InvalidDefinitionException::missingAuthorizationOfClass($class);
        }

        return $this->compileTransition(
            $model,
            $machine,
            $class::name(),
            $this->signalOf($reflection),
            $fromStates,
            $targetState,
            $class,
            TransitionSteps::none(),
            $trigger,
            $this->triggerFansOut($reflection),
            $triggerOnly,
        );
    }

    /**
     * Static, so the condition is known without building the class. A trigger applies the transition by name, so the
     * class may not take input.
     *
     * @param  ReflectionClass<Transition>  $class
     * @return (Closure(Builder<Model>): mixed)|null
     */
    private function triggerOf(ReflectionClass $class): ?Closure
    {
        if (! $class->hasMethod('trigger')) {
            return null;
        }

        $method = $class->getMethod('trigger');

        if (! $method->isStatic()) {
            throw InvalidDefinitionException::triggerNotStatic($class->getName());
        }

        if (CompiledTransition::takesInput($class->getName())) {
            throw InvalidDefinitionException::triggeredTransitionTakesInput($class->getName());
        }

        return $method->getClosure();
    }

    /**
     * A signal applies the transition by name, so a class that takes input answers only its own name.
     *
     * @param  ReflectionClass<Transition>  $class
     */
    private function signalOf(ReflectionClass $class): string
    {
        $signal = PhpAttribute::of($class, OnSignal::class)?->signal;

        if ($signal === null) {
            return $class->getName()::name();
        }

        if (CompiledTransition::takesInput($class->getName())) {
            throw InvalidDefinitionException::signalOfTransitionTakingInput($class->getName(), $signal);
        }

        return $signal;
    }

    /**
     * @param  ReflectionClass<Transition>  $class
     */
    private function triggerFansOut(ReflectionClass $class): bool
    {
        return $class->hasMethod('trigger') && $class->getMethod('trigger')->getAttributes(FanOut::class) !== [];
    }

    /**
     * @param  non-empty-list<BackedEnum>  $fromStates
     * @param  class-string<Transition>|null  $class
     * @param  (Closure(Builder<Model>): mixed)|null  $trigger
     */
    private function compileTransition(
        Model $model,
        CompiledMachine $machine,
        string $name,
        string $signal,
        array $fromStates,
        BackedEnum $targetState,
        ?string $class,
        TransitionSteps $fluentSteps,
        ?Closure $trigger,
        bool $triggerFansOut,
        bool $triggerOnly,
    ): CompiledTransition {
        $stateOfAnotherEnum = self::stateNotOf($model->getCasts()[$machine->stateAttribute] ?? null, [...$fromStates, $targetState]);

        if ($stateOfAnotherEnum !== null) {
            throw InvalidDefinitionException::stateAttributeNotCast($machine->class, $model, $machine->stateAttribute, $stateOfAnotherEnum);
        }

        if ($machine->states->isParent($targetState)) {
            throw InvalidDefinitionException::targetStateIsParentState(
                $machine->class,
                $name,
                $targetState,
                $machine->states->leavesWithin($targetState),
            );
        }

        return new CompiledTransition(
            $name,
            $signal,
            $machine->stateAttribute,
            $fromStates,
            $targetState,
            array_values(array_unique(
                array_merge(...array_map($machine->states->leavesWithin(...), $fromStates)),
                SORT_REGULAR,
            )),
            $class,
            $fluentSteps,
            $trigger,
            $triggerFansOut,
            $triggerOnly,
        );
    }

    /**
     * @param  list<BackedEnum>  $states
     */
    private static function stateNotOf(?string $enum, array $states): ?BackedEnum
    {
        return array_find($states, fn(BackedEnum $state) => $state::class !== $enum);
    }
}
