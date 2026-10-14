<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\FromStates;
use RobertBoes\Interlock\Attributes\InitialStates;
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

        foreach ((new ReflectionClass($model))->getAttributes(UseStateMachine::class) as $attribute) {
            $declaration = $attribute->newInstance();

            if (isset($machines[$declaration->attribute])) {
                throw InvalidDefinitionException::twoMachinesForOneStateAttribute($model, $declaration->attribute, $machines[$declaration->attribute]->machine, $declaration->machine);
            }

            $machine = $machines[$declaration->attribute] = $this->compileMachine($model, $declaration);

            foreach ((new $declaration->machine())->transitions() as $definition) {
                $transition = is_string($definition)
                    ? $this->compileTransitionClass($model, $machine, $definition)
                    : $this->compileFluentTransition($model, $machine, $definition);

                if (isset($transitions[$transition->name])) {
                    throw InvalidDefinitionException::duplicateTransitionName($model, $transition->name, $machines[$transitions[$transition->name]->stateAttribute], $machine);
                }

                $transitions[$transition->name] = $transition;
            }
        }

        return new ModelGraph($model::class, $machines, $transitions);
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

        return new CompiledMachine($declaration->machine, $declaration->attribute, $initialStates, $states, $finalStates);
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
            TransitionSteps::closures($definition->applyStep, $definition->afterCommitStep),
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

        return $this->compileEdge(
            $model,
            $machine,
            $class::name(),
            $fromStates,
            $targetState,
            $class,
            TransitionSteps::closures(null, null),
        );
    }

    /**
     * @param  non-empty-list<BackedEnum>  $fromStates
     * @param  class-string<Transition>|null  $class
     */
    private function compileEdge(
        Model $model,
        CompiledMachine $machine,
        string $name,
        array $fromStates,
        BackedEnum $targetState,
        ?string $class,
        TransitionSteps $fluentSteps,
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
        );
    }
}
