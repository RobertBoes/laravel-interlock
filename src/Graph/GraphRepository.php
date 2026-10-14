<?php

namespace RobertBoes\Interlock\Graph;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use RobertBoes\Interlock\Attributes\InitialStates;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\Definition\FluentTransition;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;

/**
 * Compiles each interlocked model's machines into one immutable graph, once per model class.
 */
final class GraphRepository
{
    /** @var array<class-string<Model>, ModelGraph> */
    private array $graphs = [];

    public function for(Model $model): ModelGraph
    {
        return $this->graphs[$model::class] ??= $this->compile($model);
    }

    private function compile(Model $model): ModelGraph
    {
        $machines = [];
        $transitions = [];

        foreach ((new ReflectionClass($model))->getAttributes(UseStateMachine::class) as $attribute) {
            $declaration = $attribute->newInstance();

            foreach ((new $declaration->machine())->transitions() as $definition) {
                $transitions[$definition->name] = $this->compileTransition($model, $declaration, $definition);
            }

            $machines[$declaration->attribute] = $this->compileMachine($model, $declaration);
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

        return new CompiledMachine($declaration->machine, $declaration->attribute, $initialStates);
    }

    private function compileTransition(Model $model, UseStateMachine $declaration, FluentTransition $definition): CompiledTransition
    {
        if ($definition->targetState === null) {
            throw InvalidDefinitionException::missingTargetState($declaration->machine, $definition->name);
        }

        if ($definition->fromStates === []) {
            throw InvalidDefinitionException::missingFromStates($declaration->machine, $definition->name);
        }

        $castEnum = $model->getCasts()[$declaration->attribute] ?? null;

        foreach ([...$definition->fromStates, $definition->targetState] as $state) {
            if ($state::class !== $castEnum) {
                throw InvalidDefinitionException::stateAttributeNotCast($declaration->machine, $model, $declaration->attribute, $state);
            }
        }

        return new CompiledTransition(
            $definition->name,
            $declaration->attribute,
            $definition->fromStates,
            $definition->targetState,
        );
    }
}
