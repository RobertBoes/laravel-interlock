<?php

namespace RobertBoes\Interlock;

use RobertBoes\Interlock\Definition\FluentTransition;

abstract class StateMachine
{
    /**
     * The machine's transitions: fluent definitions and transition classes, side by side.
     *
     * @return list<FluentTransition|class-string<Transition>>
     */
    abstract public function transitions(): array;

    protected function transition(string $name): FluentTransition
    {
        return new FluentTransition($name);
    }
}
