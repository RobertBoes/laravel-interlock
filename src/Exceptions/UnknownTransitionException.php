<?php

namespace RobertBoes\Interlock\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

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
}
