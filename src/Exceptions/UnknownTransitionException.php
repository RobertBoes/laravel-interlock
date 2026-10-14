<?php

namespace RobertBoes\Interlock\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use RobertBoes\Interlock\Transition;

final class UnknownTransitionException extends LogicException implements InterlockException
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $knownTransitions
     */
    public static function named(string $modelClass, string $name, array $knownTransitions): self
    {
        return new self(sprintf(
            '%s has no transition named [%s]. Its transitions are [%s].',
            $modelClass,
            $name,
            implode(', ', $knownTransitions),
        ));
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  class-string<Transition>  $transitionClass
     * @param  list<string>  $knownTransitions
     */
    public static function ofClass(string $modelClass, string $transitionClass, array $knownTransitions): self
    {
        return new self(sprintf(
            '%s has no transition of class %s. List the class in one of its state machines. Its transitions are [%s].',
            $modelClass,
            $transitionClass,
            implode(', ', $knownTransitions),
        ));
    }
}
