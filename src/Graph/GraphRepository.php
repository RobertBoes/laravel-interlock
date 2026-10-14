<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\Definition\FluentTransition;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;

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
            $machine = $this->compileMachine($model, $declaration);

            foreach ((new $declaration->machine())->transitions() as $definition) {
                $transitions[$definition->name] = $this->compileTransition($model, $machine, $definition);
            }

            $machines[$declaration->attribute] = $machine;
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

        return new CompiledMachine($declaration->machine, $declaration->attribute, $initialStates, $states);
    }

    private function compileTransition(Model $model, CompiledMachine $machine, FluentTransition $definition): CompiledTransition
    {
        if ($definition->targetState === null) {
            throw InvalidDefinitionException::missingTargetState($machine->machine, $definition->name);
        }

        if ($definition->fromStates === []) {
            throw InvalidDefinitionException::missingFromStates($machine->machine, $definition->name);
        }

        $castEnum = $model->getCasts()[$machine->stateAttribute] ?? null;

        foreach ([...$definition->fromStates, $definition->targetState] as $state) {
            if ($state::class !== $castEnum) {
                throw InvalidDefinitionException::stateAttributeNotCast($machine->machine, $model, $machine->stateAttribute, $state);
            }
        }

        if ($machine->states->isParent($definition->targetState)) {
            throw InvalidDefinitionException::targetStateIsParentState(
                $machine->machine,
                $definition->name,
                $definition->targetState,
                $machine->states->leavesWithin($definition->targetState),
            );
        }

        return new CompiledTransition(
            $definition->name,
            $machine->stateAttribute,
            $definition->fromStates,
            $definition->targetState,
            array_values(array_unique(
                array_merge(...array_map($machine->states->leavesWithin(...), $definition->fromStates)),
                SORT_REGULAR,
            )),
        );
    }
}
