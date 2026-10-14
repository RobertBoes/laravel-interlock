<?php

namespace RobertBoes\Interlock\Graph;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Exceptions\UnknownTransitionException;

final readonly class ModelGraph
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, CompiledTransition>  $transitions  keyed by transition name
     */
    public function __construct(
        private string $modelClass,
        private array $transitions,
    ) {}

    public function transition(string $name): CompiledTransition
    {
        return $this->transitions[$name]
            ?? throw UnknownTransitionException::named($this->modelClass, $name, array_keys($this->transitions));
    }
}
