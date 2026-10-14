<?php

namespace RobertBoes\Interlock;

use RobertBoes\Interlock\Definition\FluentTransition;

abstract class StateMachine
{
    /**
     * @return list<FluentTransition>
     */
    abstract public function transitions(): array;

    protected function transition(string $name): FluentTransition
    {
        return new FluentTransition($name);
    }
}
