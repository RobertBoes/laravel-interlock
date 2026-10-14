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
     * @param  array<string, list<string>>  $guardedAttributes  the names of the transitions that may write each guarded attribute
     * @param  array<string, list<string>>  $guardedAttributeMachines  the state attributes of the machines that declare each guarded attribute
     */
    public function __construct(
        private string $modelClass,
        public array $machines,
        private array $transitions,
        public array $guardedAttributes,
        private array $guardedAttributeMachines = [],
    ) {}

    /**
     * @return list<CompiledTransition>
     */
    public function transitions(): array
    {
        return array_values($this->transitions);
    }

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

    /**
     * The state attributes of the machines that declare the guarded attribute.
     *
     * @return list<string>
     */
    public function machinesGuarding(string $attribute): array
    {
        return $this->guardedAttributeMachines[$attribute] ?? [];
    }

    public function recordsHistory(string $stateAttribute): bool
    {
        return $this->machines[$stateAttribute]->recordsHistory;
    }

    public function recordsAnyHistory(): bool
    {
        return array_any($this->machines, fn(CompiledMachine $machine) => $machine->recordsHistory);
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
