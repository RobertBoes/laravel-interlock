<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use RobertBoes\Interlock\Attributes\Authorise;
use RobertBoes\Interlock\Attributes\FanOut;
use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\GuardedAttribute;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\RecordsHistory;
use RobertBoes\Interlock\Attributes\TargetState;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\Definition\FluentTransition;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\Transition;

/**
 * Compiles each interlocked model's machines into one immutable graph, once per model class, and each state enum's nesting once per enum.
 */
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
        $guardedAttributes = [];
        $guardedAttributeMachines = [];

        foreach ((new ReflectionClass($model))->getAttributes(UseStateMachine::class) as $attribute) {
            $declaration = $attribute->newInstance();

            if (isset($machines[$declaration->attribute])) {
                throw InvalidDefinitionException::twoMachinesForOneStateAttribute($model, $declaration->attribute, $machines[$declaration->attribute]->machine, $declaration->machine);
            }

            $machine = $machines[$declaration->attribute] = $this->compileMachine($model, $declaration);
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

            foreach ($this->compileGuardedAttributes($machine, $machineTransitions) as $attribute => $writers) {
                $guardedAttributes[$attribute] = [...$guardedAttributes[$attribute] ?? [], ...$writers];
                $guardedAttributeMachines[$attribute][] = $machine->stateAttribute;
            }
        }

        return new ModelGraph($model::class, $machines, $transitions, $guardedAttributes, $guardedAttributeMachines);
    }

    private function compileMachine(Model $model, UseStateMachine $declaration): CompiledMachine
    {
        $attribute = (new ReflectionClass($declaration->machine))->getAttributes(InitialStates::class)[0] ?? null;
        $initialStates = $attribute?->newInstance()->states ?? [];

        if ($initialStates === []) {
            throw InvalidDefinitionException::missingInitialStates($declaration->machine);
        }

        $castEnum = $model->getCasts()[$declaration->attribute] ?? null;

        foreach ($initialStates as $state) {
            if ($castEnum === null) {
                throw InvalidDefinitionException::stateAttributeNotCast($declaration->machine, $model, $declaration->attribute, $state);
            }

            if ($state::class !== $castEnum) {
                throw InvalidDefinitionException::initialStateNotOfCastEnum($declaration->machine, $model, $declaration->attribute, $state, $castEnum);
            }
        }

        $states = $this->stateTree($initialStates[0]::class);

        foreach ($initialStates as $state) {
            if ($states->isParent($state)) {
                throw InvalidDefinitionException::initialStateIsParentState($declaration->machine, $state, $states->leavesWithin($state));
            }
        }

        $attribute = (new ReflectionClass($declaration->machine))->getAttributes(FinalStates::class)[0] ?? null;
        $finalStates = $attribute?->newInstance()->states ?? [];

        foreach ($finalStates as $state) {
            if ($state::class !== $castEnum) {
                throw InvalidDefinitionException::finalStateNotOfCastEnum($declaration->machine, $model, $declaration->attribute, $state, $castEnum);
            }
        }

        $recordsHistory = (new ReflectionClass($declaration->machine))->getAttributes(RecordsHistory::class) !== [];

        return new CompiledMachine($declaration->machine, $declaration->attribute, $initialStates, $states, $finalStates, $recordsHistory);
    }

    /**
     * @param  list<CompiledTransition>  $transitions  the machine's own transitions
     * @return array<string, list<string>> the names of the transitions that may write each guarded attribute
     */
    private function compileGuardedAttributes(CompiledMachine $machine, array $transitions): array
    {
        $guardedAttributes = [];

        foreach ((new ReflectionClass($machine->machine))->getAttributes(GuardedAttribute::class) as $attribute) {
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
        foreach ($transitions as $transition) {
            if ($transition->name === $nameOrClass || $transition->class === $nameOrClass) {
                return $transition->name;
            }
        }

        throw InvalidDefinitionException::guardedAttributeWrittenByUnknownTransition(
            $machine->machine,
            $guardedAttribute,
            $nameOrClass,
            array_map(fn(CompiledTransition $transition) => $transition->name, $transitions),
        );
    }

    private function compileFluentTransition(Model $model, CompiledMachine $machine, FluentTransition $definition): CompiledTransition
    {
        if ($definition->targetState === null) {
            throw InvalidDefinitionException::missingTargetState($machine->machine, $definition->name);
        }

        if ($definition->fromStates === []) {
            throw InvalidDefinitionException::missingFromStates($machine->machine, $definition->name);
        }

        return $this->compileEdge(
            $model,
            $machine,
            $definition->name,
            $definition->fromStates,
            $definition->targetState,
            null,
            TransitionSteps::fluent($definition),
            $definition->trigger,
            $definition->triggerFansOut,
        );
    }

    /**
     * Reads the from-states and target state from the class's attributes, so the class is never instantiated.
     *
     * @param  class-string<Transition>  $class
     */
    private function compileTransitionClass(Model $model, CompiledMachine $machine, string $class): CompiledTransition
    {
        if (! is_subclass_of($class, Transition::class)) {
            throw InvalidDefinitionException::notATransitionClass($machine->machine, $class);
        }

        $reflection = new ReflectionClass($class);
        $fromStates = ($reflection->getAttributes(FromStates::class)[0] ?? null)?->newInstance()->states ?? [];
        $targetState = ($reflection->getAttributes(TargetState::class)[0] ?? null)?->newInstance()->state
            ?? throw InvalidDefinitionException::missingTargetStateAttribute($class);

        if ($fromStates === []) {
            throw InvalidDefinitionException::missingFromStatesAttribute($class);
        }

        $ability = ($reflection->getAttributes(Authorise::class)[0] ?? null)?->newInstance()->ability;

        if ($ability !== null && $reflection->hasMethod('authorise')) {
            throw InvalidDefinitionException::authorisedTwice($class, $ability);
        }

        return $this->compileEdge(
            $model,
            $machine,
            $class::name(),
            $fromStates,
            $targetState,
            $class,
            TransitionSteps::none(),
            $this->triggerOf($reflection),
            $this->triggerFansOut($reflection),
        );
    }

    /**
     * A transition class declares its trigger as a static trigger() method, so the condition is known without building
     * the class, which may take input.
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

        return $method->getClosure();
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
    private function compileEdge(
        Model $model,
        CompiledMachine $machine,
        string $name,
        array $fromStates,
        BackedEnum $targetState,
        ?string $class,
        TransitionSteps $fluentSteps,
        ?Closure $trigger,
        bool $triggerFansOut,
    ): CompiledTransition {
        $castEnum = $model->getCasts()[$machine->stateAttribute] ?? null;

        foreach ([...$fromStates, $targetState] as $state) {
            if ($state::class !== $castEnum) {
                throw InvalidDefinitionException::stateAttributeNotCast($machine->machine, $model, $machine->stateAttribute, $state);
            }
        }

        if ($machine->states->isParent($targetState)) {
            throw InvalidDefinitionException::targetStateIsParentState(
                $machine->machine,
                $name,
                $targetState,
                $machine->states->leavesWithin($targetState),
            );
        }

        return new CompiledTransition(
            $name,
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
        );
    }
}
