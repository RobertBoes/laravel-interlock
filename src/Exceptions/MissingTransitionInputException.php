<?php

namespace RobertBoes\Interlock\Exceptions;

use InvalidArgumentException;
use RobertBoes\Interlock\Graph\CompiledTransition;
use Throwable;

final class MissingTransitionInputException extends InvalidArgumentException implements InterlockException
{
    /**
     * @param  class-string  $class
     */
    public static function appliedByName(CompiledTransition $transition, string $class, Throwable $previous): self
    {
        return new self(sprintf(
            'Transition [%s] takes input, so %s cannot be built from its name. Apply it as an instance: ->transition(new %s(...)).',
            $transition->name,
            $class,
            $class,
        ), previous: $previous);
    }
}
