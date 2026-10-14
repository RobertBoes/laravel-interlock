<?php

namespace RobertBoes\Interlock\Graph;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;
use RobertBoes\Interlock\Transition;

final readonly class ModelGraph
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, CompiledMachine>  $machines  keyed by state attribute
     * @param  array<string, CompiledTransition>  $transitions  keyed by transition name
     */
    public function __construct(
        private string $modelClass,
        public array $machines,
        private array $transitions,
    ) {}

    public function transition(string $name): CompiledTransition
    {
        return $this->transitions[$name]
            ?? throw UnknownTransitionException::named($this->modelClass, $name, array_keys($this->transitions));
    }

    /**
     * @return list<CompiledTransition>
     */
    public function transitionsOf(CompiledMachine $machine): array
    {
        return array_values(array_filter(
            $this->transitions,
            fn(CompiledTransition $transition) => $transition->stateAttribute === $machine->stateAttribute,
        ));
    }

    public function transitionOfClass(Transition $transition): CompiledTransition
    {
        $compiled = $this->transitions[$transition::name()] ?? null;

        if ($compiled?->class !== $transition::class) {
            throw UnknownTransitionException::ofClass($this->modelClass, $transition::class, array_keys($this->transitions));
        }

        return $compiled;
    }
}
