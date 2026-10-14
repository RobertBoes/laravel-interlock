<?php

namespace RobertBoes\Interlock\Graph;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
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
        $transitions = [];

        foreach ((new ReflectionClass($model))->getAttributes(UseStateMachine::class) as $attribute) {
            $declaration = $attribute->newInstance();

            foreach ((new $declaration->machine)->transitions() as $definition) {
                $transitions[$definition->name] = $this->compileTransition($model, $declaration, $definition);
            }
        }

        return new ModelGraph($model::class, $transitions);
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
